<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\Query\Conditions;
use Shirakaba\Query\Delete;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;
use Shirakaba\Query\Update;
use Shirakaba\Time\SystemClock;

/**
 * Jobs in a database table (design/10-background-work.md), on MySQL 8, PostgreSQL and SQLite.
 *
 * Pushing inserts into the caller's transaction (a transactional outbox). Workers reserve
 * with a lease: `FOR UPDATE SKIP LOCKED` lets concurrent workers take different jobs on
 * MySQL and PostgreSQL; SQLite serialises writers and the reservation re-checks the lease.
 * Every reservation gets a token, so a worker whose lease expired cannot acknowledge a job
 * another worker now holds.
 *
 * Workers open their own connection on first use and keep it; the queue object is shared
 * by requests only for push(), which uses the caller's Writer.
 */
final class DatabaseQueue implements Queue, WaitsForJobs
{
    /** The channel pushes notify on PostgreSQL; the payload is the queue name. */
    public const string CHANNEL = 'shirakaba_jobs';

    private ?Connection $connection = null;
    private bool $listening = false;

    /**
     * @param bool $notify on PostgreSQL, wake idle workers with NOTIFY when a job is pushed
     *                     (delivered at commit) instead of letting them poll
     */
    public function __construct(
        private readonly Database $database,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly string $table = 'shirakaba_jobs',
        private readonly bool $notify = true,
    ) {}

    public function push(string $type, string $payload, Writer $db, PushOptions $options): bool
    {
        $now = $this->clock->now();
        $insert = Insert::into($this->table)->values([
            'queue' => $options->queue,
            'type' => $type,
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => $options->maxAttempts,
            'available_at' => $options->availableAt ?? $now->modify(sprintf('+%d seconds', $options->delay)),
            'reserved_until' => null,
            'reservation' => null,
            'unique_key' => $options->uniqueKey,
            'traceparent' => TraceContext::current(),
            'failed_at' => null,
            'error' => null,
            'created_at' => $now,
        ]);
        if ($options->uniqueKey !== null) {
            $insert = $insert->onConflict(['unique_key']);
        }
        $pushed = $insert->execute($db) > 0;
        if ($pushed && $this->notify && $db->dialect()->name() === 'pgsql') {
            $db->fetchValue('SELECT pg_notify(?, ?)', [self::CHANNEL, $options->queue]);
        }

        return $pushed;
    }

    /**
     * On PostgreSQL, waits for a NOTIFY from push() on the worker's connection; elsewhere, or
     * with notifications off, sleeps. Notifications for other queues end the wait early, and
     * the worker simply finds nothing to reserve.
     *
     * @param list<string> $queues
     */
    public function waitForJobs(array $queues, float $seconds, Cancellation $cancellation): void
    {
        $deadline = hrtime(true) + (int) ($seconds * 1e9);
        $connection = $this->notify ? $this->listen() : null;
        while (!$cancellation->isRequested() && hrtime(true) < $deadline) {
            $step = (int) min(100, max(1, ($deadline - hrtime(true)) / 1e6));
            if ($connection === null) {
                usleep($step * 1000);
                continue;
            }
            $notification = $connection->waitForNotification($step);
            if ($notification !== null && in_array($notification['payload'], $queues, true)) {
                return;
            }
        }
    }

    /**
     * LISTENs on the worker's connection once, on PostgreSQL with a connection that receives
     * notifications.
     */
    private function listen(): ?Connection
    {
        $db = $this->writer();
        $connection = $this->connection;
        if ($connection === null || !$connection->receivesNotifications()) {
            return null;
        }
        if (!$this->listening) {
            $db->execute('LISTEN ' . $db->dialect()->quoteIdentifier(self::CHANNEL));
            $this->listening = true;
        }

        return $connection;
    }

    /**
     * @param non-empty-list<string> $queues
     * @param positive-int $leaseSeconds
     */
    public function reserve(array $queues, int $leaseSeconds): ?ReservedJob
    {
        $db = $this->writer();

        return $db->transaction(function (Writer $db) use ($queues, $leaseSeconds): ?ReservedJob {
            $now = $this->clock->now();
            $due = static fn (Conditions $c): Conditions => $c->whereNull('reserved_until')->orWhere('reserved_until', '<', $now);
            $id = Select::from($this->table)
                ->columns('id')
                ->whereIn('queue', $queues)
                ->whereNull('failed_at')
                ->where('available_at', '<=', $now)
                ->whereGroup($due)
                ->orderBy('available_at')
                ->orderBy('id')
                ->limit(1)
                ->forUpdateSkipLocked()
                ->fetchValue($db);
            if (!is_int($id) && !is_string($id)) {
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $taken = Update::table($this->table)
                ->set([
                    'reserved_until' => $now->modify(sprintf('+%d seconds', $leaseSeconds)),
                    'reservation' => $token,
                    'attempts' => Sql::raw($db->dialect()->quoteIdentifier('attempts') . ' + 1'),
                ])
                ->where('id', '=', $id)
                ->whereNull('failed_at')
                // Re-checked for SQLite, where no row lock kept another worker out.
                ->whereGroup($due)
                ->execute($db);
            if ($taken === 0) {
                return null;
            }
            $row = Select::from($this->table)->where('id', '=', $id)->fetchOne($db) ?? [];

            return new ReservedJob(
                (string) $id,
                self::string($row['queue'] ?? ''),
                self::string($row['type'] ?? ''),
                self::string($row['payload'] ?? ''),
                max(1, self::int($row['attempts'] ?? 1)),
                max(1, self::int($row['max_attempts'] ?? 1)),
                $token,
                is_string($row['traceparent'] ?? null) ? $row['traceparent'] : null,
            );
        });
    }

    public function acknowledge(ReservedJob $job): bool
    {
        return Delete::from($this->table)->where('id', '=', $job->id)->where('reservation', '=', $job->reservation)->execute($this->writer()) > 0;
    }

    public function release(ReservedJob $job, int $delaySeconds): bool
    {
        return $this->finish($job, [
            'available_at' => $this->clock->now()->modify(sprintf('+%d seconds', $delaySeconds)),
        ]);
    }

    public function fail(ReservedJob $job, string $error): bool
    {
        return $this->finish($job, [
            'failed_at' => $this->clock->now(),
            'error' => mb_substr($error, 0, 2000),
            // A failed job no longer blocks a new one with the same key.
            'unique_key' => null,
        ]);
    }

    /**
     * Failed jobs, newest first, for inspection.
     *
     * @return list<array{id: string, queue: string, type: string, attempts: int, failedAt: DateTimeImmutable|null, error: string}>
     * @phpstan-impure io.db
     */
    public function failed(int $limit = 50): array
    {
        $db = $this->writer();
        $failed = [];
        foreach (Select::from($this->table)
            ->whereNotNull('failed_at')
            ->orderBy('failed_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(max(1, $limit))
            ->fetchAll($db) as $row) {
            $failedAt = $row['failed_at'] ?? null;
            $failed[] = [
                'id' => self::string($row['id'] ?? ''),
                'queue' => self::string($row['queue'] ?? ''),
                'type' => self::string($row['type'] ?? ''),
                'attempts' => self::int($row['attempts'] ?? 0),
                'failedAt' => is_string($failedAt) ? $db->dialect()->parseInstant($failedAt) : null,
                'error' => self::string($row['error'] ?? ''),
            ];
        }

        return $failed;
    }

    /**
     * Puts a failed job back in its queue with a fresh attempt count.
     */
    public function retry(string $id): bool
    {
        return (
            Update::table($this->table)
                ->set(['failed_at' => null, 'error' => null, 'attempts' => 0, 'available_at' => $this->clock->now()])
                ->where('id', '=', $id)
                ->whereNotNull('failed_at')
                ->execute($this->writer()) > 0
        );
    }

    /**
     * Deletes a failed job.
     */
    public function forget(string $id): bool
    {
        return Delete::from($this->table)->where('id', '=', $id)->whereNotNull('failed_at')->execute($this->writer()) > 0;
    }

    /**
     * Closes the worker's connection, so the next operation opens a new one.
     */
    public function disconnect(): void
    {
        $this->connection = null;
        $this->listening = false;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function finish(ReservedJob $job, array $changes): bool
    {
        return (
            Update::table($this->table)
                ->set([...$changes, 'reserved_until' => null, 'reservation' => null])
                ->where('id', '=', $job->id)
                ->where('reservation', '=', $job->reservation)
                ->execute($this->writer()) > 0
        );
    }

    private function writer(): Writer
    {
        $this->connection ??= $this->database->connect();

        return $this->connection->writer();
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : 0);
    }
}
