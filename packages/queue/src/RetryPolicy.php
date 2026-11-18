<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use InvalidArgumentException;

/**
 * How long a failed job waits before its next attempt: exponential backoff with jitter, so
 * jobs that failed together do not retry together.
 */
final readonly class RetryPolicy
{
    /**
     * @param positive-int $baseSeconds the wait after the first failure
     * @param positive-int $maxSeconds
     * @param float $jitter the share of the wait that is randomised, 0 to 1
     * @throws InvalidArgumentException for an invalid jitter
     */
    public function __construct(
        public int $baseSeconds = 10,
        public int $maxSeconds = 3600,
        public float $jitter = 0.2,
    ) {
        if ($jitter < 0 || $jitter > 1) {
            throw new InvalidArgumentException('The jitter must be between 0 and 1.');
        }
    }

    /**
     * @param int<1, max> $attempt the attempt that just failed
     * @return int<0, max> seconds to wait
     */
    public function delayAfter(int $attempt): int
    {
        $delay = min($this->maxSeconds, $this->baseSeconds * (2 ** min(30, $attempt - 1)));
        $spread = (int) round($delay * $this->jitter);

        return max(0, $delay + ($spread === 0 ? 0 : random_int(-$spread, $spread)));
    }
}
