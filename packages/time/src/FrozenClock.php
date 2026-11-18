<?php

declare(strict_types=1);

namespace Shirakaba\Time;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * A clock for tests: it reports the same instant until moved.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $now = '2026-01-01T00:00:00Z')
    {
        $this->now = self::utc($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable|string $now): void
    {
        $this->now = self::utc($now);
    }

    /**
     * Moves the clock forward by an ISO 8601 duration ("PT30S") or a number of seconds.
     */
    public function advance(DateInterval|string|int $by): void
    {
        $interval = match (true) {
            $by instanceof DateInterval => $by,
            is_int($by) => new DateInterval(sprintf('PT%dS', abs($by))),
            default => new DateInterval($by),
        };
        $this->now = is_int($by) && $by < 0 ? $this->now->sub($interval) : $this->now->add($interval);
    }

    private static function utc(DateTimeImmutable|string $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return ($value instanceof DateTimeImmutable ? $value : new DateTimeImmutable($value, $utc))->setTimezone($utc);
    }
}
