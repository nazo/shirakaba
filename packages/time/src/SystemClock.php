<?php

declare(strict_types=1);

namespace Shirakaba\Time;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The wall clock, always in UTC whatever php.ini's date.timezone says.
 *
 * Use it for "when" (timestamps, expiry). Durations and deadlines use the monotonic
 * hrtime() instead, which never jumps when the system clock is adjusted.
 */
final readonly class SystemClock implements ClockInterface
{
    /**
     * @phpstan-impure nondet.time
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
