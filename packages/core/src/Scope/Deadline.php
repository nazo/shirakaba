<?php

declare(strict_types=1);

namespace Shirakaba\Core\Scope;

use Shirakaba\Core\Http\HttpFailure;

/**
 * A point in time by which a request should finish, on the monotonic clock (design/03-runtimes-and-scopes.md).
 * Cooperative like Cancellation.
 */
final readonly class Deadline
{
    private function __construct(
        /** Monotonic time in nanoseconds, as returned by hrtime(true). */
        private int $at,
    ) {}

    /**
     * hrtime() returns a float only on 32-bit builds, where nanoseconds overflow an int.
     */
    private static function now(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }

    /**
     * @phpstan-impure nondet.time
     */
    public static function in(float $seconds): self
    {
        return new self(self::now() + (int) round($seconds * 1e9));
    }

    /**
     * @phpstan-impure nondet.time
     */
    public function remaining(): float
    {
        return max(0.0, ($this->at - self::now()) / 1e9);
    }

    /**
     * @phpstan-impure nondet.time
     */
    public function isExpired(): bool
    {
        return self::now() >= $this->at;
    }

    /**
     * @throws HttpFailure a 503 when the deadline has passed
     */
    public function throwIfExpired(): void
    {
        if ($this->isExpired()) {
            throw new HttpFailure(503, 'The request did not finish in time.');
        }
    }
}
