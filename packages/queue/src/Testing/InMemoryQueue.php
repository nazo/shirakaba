<?php

declare(strict_types=1);

namespace Shirakaba\Queue\Testing;

use Shirakaba\Database\Writer;
use Shirakaba\Queue\PushOptions;
use Shirakaba\Queue\Queue;
use Shirakaba\Queue\ReservedJob;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * A queue in process memory for tests. Like queues outside the database, it accepts a job
 * only when the pushing transaction commits.
 */
final class InMemoryQueue implements Queue
{
    /** @var array<int, array{type: string, payload: string, queue: string, availableAt: int, attempts: int, maxAttempts: int, reservation: ?string, until: int, failed: ?string, unique: ?string}> */
    private array $jobs = [];

    private int $next = 1;

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function push(string $type, string $payload, Writer $db, PushOptions $options): bool
    {
        if ($options->uniqueKey !== null) {
            foreach ($this->jobs as $job) {
                if ($job['unique'] === $options->uniqueKey && $job['failed'] === null) {
                    return false;
                }
            }
        }
        $now = $this->clock->now()->getTimestamp();
        $db->afterCommit(function () use ($type, $payload, $options, $now): void {
            $this->jobs[$this->next++] = [
                'type' => $type,
                'payload' => $payload,
                'queue' => $options->queue,
                'availableAt' => $options->availableAt?->getTimestamp() ?? $now + $options->delay,
                'attempts' => 0,
                'maxAttempts' => $options->maxAttempts,
                'reservation' => null,
                'until' => 0,
                'failed' => null,
                'unique' => $options->uniqueKey,
            ];
        });

        return true;
    }

    /**
     * @param non-empty-list<string> $queues
     * @param positive-int $leaseSeconds
     */
    public function reserve(array $queues, int $leaseSeconds): ?ReservedJob
    {
        $now = $this->clock->now()->getTimestamp();
        foreach ($this->jobs as $id => $job) {
            if ($job['failed'] !== null || !in_array($job['queue'], $queues, true) || $job['availableAt'] > $now || ($job['reservation'] !== null && $job['until'] >= $now)) {
                continue;
            }
            $token = bin2hex(random_bytes(16));
            $attempt = $job['attempts'] + 1;
            $this->jobs[$id] = [...$job, 'attempts' => $attempt, 'reservation' => $token, 'until' => $now + $leaseSeconds];

            return new ReservedJob((string) $id, $job['queue'], $job['type'], $job['payload'], max(1, $attempt), max(1, $job['maxAttempts']), $token);
        }

        return null;
    }

    public function acknowledge(ReservedJob $job): bool
    {
        $id = (int) $job->id;
        if (($this->jobs[$id]['reservation'] ?? null) !== $job->reservation) {
            return false;
        }
        unset($this->jobs[$id]);

        return true;
    }

    public function release(ReservedJob $job, int $delaySeconds): bool
    {
        $stored = $this->jobs[(int) $job->id] ?? null;
        if ($stored === null || $stored['reservation'] !== $job->reservation) {
            return false;
        }
        $this->jobs[(int) $job->id] = [...$stored, 'reservation' => null, 'availableAt' => $this->clock->now()->getTimestamp() + $delaySeconds];

        return true;
    }

    public function fail(ReservedJob $job, string $error): bool
    {
        $stored = $this->jobs[(int) $job->id] ?? null;
        if ($stored === null || $stored['reservation'] !== $job->reservation) {
            return false;
        }
        $this->jobs[(int) $job->id] = [...$stored, 'reservation' => null, 'failed' => $error, 'unique' => null];

        return true;
    }

    /**
     * Jobs waiting to run, by type, for assertions.
     *
     * @return list<string>
     */
    public function pendingTypes(): array
    {
        return array_values(array_map(static fn (array $job): string => $job['type'], array_filter($this->jobs, static fn (array $job): bool => $job['failed'] === null)));
    }

    /**
     * @return list<string> errors of failed jobs
     */
    public function failures(): array
    {
        return array_values(array_filter(array_map(static fn (array $job): ?string => $job['failed'], $this->jobs), is_string(...)));
    }
}
