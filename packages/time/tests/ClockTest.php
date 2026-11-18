<?php

declare(strict_types=1);

namespace Shirakaba\Time\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Time\FrozenClock;
use Shirakaba\Time\SystemClock;

#[CoversClass(SystemClock::class)]
#[CoversClass(FrozenClock::class)]
final class ClockTest extends TestCase
{
    public function testSystemClockIsUtcWhateverTheDefaultTimezone(): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $now = new SystemClock()->now();
        } finally {
            date_default_timezone_set($default);
        }

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertEqualsWithDelta(time(), $now->getTimestamp(), 2);
    }

    public function testFrozenClockStandsStillUntilMoved(): void
    {
        $clock = new FrozenClock('2026-11-11T09:00:00+09:00');

        self::assertSame('2026-11-11T00:00:00+00:00', $clock->now()->format(DATE_ATOM));
        self::assertSame($clock->now(), $clock->now());

        $clock->advance(90);
        self::assertSame('2026-11-11T00:01:30+00:00', $clock->now()->format(DATE_ATOM));
        $clock->advance('PT1H');
        self::assertSame('2026-11-11T01:01:30+00:00', $clock->now()->format(DATE_ATOM));
        $clock->advance(-30);
        self::assertSame('2026-11-11T01:01:00+00:00', $clock->now()->format(DATE_ATOM));
        $clock->set('2027-01-01T00:00:00Z');
        self::assertSame('2027-01-01T00:00:00+00:00', $clock->now()->format(DATE_ATOM));
    }
}
