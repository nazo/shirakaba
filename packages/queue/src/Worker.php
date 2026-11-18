<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Shirakaba\Console\Output;
use Shirakaba\Core\Scope\Cancellation;

/**
 * The loop of `queue:work`: runs jobs one at a time and stops between jobs when cancelled
 * (SIGTERM, SIGINT), after a number of jobs, above a memory limit, or when the queue is
 * empty if asked. A process manager (systemd, a container runtime) restarts it, which also
 * releases anything a job leaked, as worker runtimes do after a number of requests.
 *
 * An idle worker waits for a job instead of polling when the queue can wake it (WaitsForJobs,
 * NOTIFY on PostgreSQL). `$rates` limits how many jobs per second this worker takes from a
 * queue, for work that calls rate-limited services; with several workers, each
 * keeps its own limit.
 */
final class Worker
{
    private int $ran = 0;

    /** @var array<string, int|float> queue => earliest next start (hrtime) */
    private array $nextStart = [];

    /**
     * @param non-empty-list<string> $queues
     * @param positive-int $leaseSeconds
     * @param array<string, float> $rates queue => jobs per second
     * @throws \InvalidArgumentException for a rate that is not positive
     */
    public function __construct(
        private readonly JobRunner $runner,
        private readonly array $queues,
        private readonly int $leaseSeconds,
        private readonly array $rates = [],
    ) {
        foreach ($rates as $queue => $rate) {
            if ($rate <= 0) {
                throw new \InvalidArgumentException(sprintf('The rate of queue "%s" must be positive.', $queue));
            }
        }
    }

    /**
     * @param int<0, max> $sleepSeconds
     * @param int<0, max> $maxJobs 0 for no limit
     * @return string why the worker stopped
     */
    public function run(Cancellation $cancellation, int $sleepSeconds, int $maxJobs, int $maxMemoryBytes, bool $stopWhenEmpty, ?Output $output = null): string
    {
        while (true) {
            if ($cancellation->isRequested()) {
                return 'cancelled';
            }
            if ($maxJobs > 0 && $this->ran >= $maxJobs) {
                return 'job limit reached';
            }
            if (memory_get_usage(true) > $maxMemoryBytes) {
                return 'memory limit reached';
            }
            $now = hrtime(true);
            $open = array_values(array_filter($this->queues, fn (string $queue): bool => ($this->nextStart[$queue] ?? 0) <= $now));
            if ($open === []) {
                // Every queue is at its rate limit: wait for the first to open.
                $first = $this->nextStart === [] ? $now : min($this->nextStart);
                self::pause($first - $now, $cancellation);
                continue;
            }
            $result = $this->runner->runNext($open, $this->leaseSeconds, $cancellation);
            if ($result === null) {
                if ($stopWhenEmpty) {
                    return 'queue empty';
                }
                $queue = $this->runner->queue();
                if ($queue instanceof WaitsForJobs) {
                    $queue->waitForJobs($open, $sleepSeconds, $cancellation);
                } else {
                    self::pause($sleepSeconds * 1_000_000_000, $cancellation);
                }
                continue;
            }
            $this->ran++;
            [$job, $outcome, $error] = $result;
            $rate = $this->rates[$job->queue] ?? null;
            if ($rate !== null) {
                $this->nextStart[$job->queue] = $now + 1e9 / $rate;
            }
            $output?->line(sprintf('%s %s #%s (attempt %d)%s', $outcome->value, $job->type, $job->id, $job->attempt, $error === null ? '' : ': ' . $error->getMessage()));
            gc_collect_cycles();
        }
    }

    public function ran(): int
    {
        return $this->ran;
    }

    /**
     * Sleeps in short steps, so a signal stops the worker promptly.
     */
    private static function pause(int|float $nanoseconds, Cancellation $cancellation): void
    {
        $until = hrtime(true) + $nanoseconds;
        while (!$cancellation->isRequested() && hrtime(true) < $until) {
            usleep((int) min(100_000, max(1, ($until - hrtime(true)) / 1000)));
        }
    }
}
