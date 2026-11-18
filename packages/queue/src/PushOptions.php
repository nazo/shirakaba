<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How a job is queued.
 */
final readonly class PushOptions
{
    /**
     * @param int<0, max> $delay seconds before the job may run
     * @param string|null $uniqueKey while a job with this key is waiting or running, pushing
     *                               another one with the same key does nothing
     * @param int<1, max> $maxAttempts runs before the job is marked failed
     * @throws InvalidArgumentException for an invalid queue name or key
     */
    public function __construct(
        public string $queue = 'default',
        public int $delay = 0,
        public ?DateTimeImmutable $availableAt = null,
        public ?string $uniqueKey = null,
        public int $maxAttempts = 5,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/D', $queue) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid queue name "%s".', $queue));
        }
        if ($uniqueKey !== null && ($uniqueKey === '' || strlen($uniqueKey) > 191)) {
            throw new InvalidArgumentException('A unique key must be 1 to 191 bytes.');
        }
    }
}
