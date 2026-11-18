<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Writer;

/**
 * Where jobs wait (design/10-background-work.md). Pushing takes the caller's Writer: the database queue inserts
 * the job in the caller's transaction, other queues push after it commits, so a job never
 * exists for data that was rolled back.
 */
interface Queue
{
    /**
     * @return bool false when a job with the same unique key is already waiting
     * @throws DatabaseError
     * @phpstan-impure io.db
     */
    public function push(string $type, string $payload, Writer $db, PushOptions $options): bool;

    /**
     * Takes the next job that is due from $queues, holding it for $leaseSeconds.
     *
     * @param non-empty-list<string> $queues
     * @param positive-int $leaseSeconds
     * @phpstan-impure io.db
     */
    public function reserve(array $queues, int $leaseSeconds): ?ReservedJob;

    /**
     * The job is done; it is removed.
     *
     * @return bool false when the reservation was lost (the lease expired and another worker took the job)
     * @phpstan-impure io.db
     */
    public function acknowledge(ReservedJob $job): bool;

    /**
     * The job should run again after $delaySeconds.
     *
     * @param int<0, max> $delaySeconds
     * @phpstan-impure io.db
     */
    public function release(ReservedJob $job, int $delaySeconds): bool;

    /**
     * The job will not run again; it is kept as failed with the error's class and message.
     * @phpstan-impure io.db
     */
    public function fail(ReservedJob $job, string $error): bool;
}
