<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

/**
 * A job a worker holds until it acknowledges, releases or fails it, or its lease expires.
 */
final readonly class ReservedJob
{
    /**
     * @param int<1, max> $attempt this run's number, starting at 1
     */
    public function __construct(
        public string $id,
        public string $queue,
        public string $type,
        public string $payload,
        public int $attempt,
        public int $maxAttempts,
        /** Identifies this reservation; a worker whose lease expired cannot finish the job. */
        public string $reservation,
        public ?string $traceparent = null,
    ) {}
}
