<?php

declare(strict_types=1);

namespace Shirakaba\Time\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Time\FrozenClock;
use Shirakaba\Time\LocalDate;

#[CoversClass(LocalDate::class)]
final class LocalDateTest extends TestCase
{
    public function testParsesAndFormats(): void
    {
        $date = LocalDate::parse('2026-11-11');

        self::assertNotNull($date);
        self::assertSame([2026, 11, 11], [$date->year, $date->month, $date->day]);
        self::assertSame('2026-11-11', (string) $date);
        self::assertSame('0001-01-01', LocalDate::of(1, 1, 1)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'impossible' => ['2026-02-29'];
        yield 'year zero' => ['0000-01-01'];
        yield 'short' => ['2026-1-1'];
        yield 'with time' => ['2026-11-11T00:00:00Z'];
        yield 'trailing newline' => ["2026-11-11\n"];
    }

    #[DataProvider('invalid')]
    public function testRejects(string $value): void
    {
        self::assertNull(LocalDate::parse($value));
    }

    public function testOfRejectsImpossibleDates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LocalDate::of(2026, 4, 31);
    }

    public function testTheDayDependsOnTheTimezone(): void
    {
        $instant = new DateTimeImmutable('2026-11-10T20:00:00Z');

        self::assertSame('2026-11-10', LocalDate::fromInstant($instant, new DateTimeZone('UTC'))->toString());
        self::assertSame('2026-11-11', LocalDate::fromInstant($instant, new DateTimeZone('Asia/Tokyo'))->toString());
        self::assertSame('2026-11-11', LocalDate::today(new FrozenClock($instant), new DateTimeZone('Asia/Tokyo'))->toString());
    }

    public function testStartOfDayAndArithmetic(): void
    {
        $date = LocalDate::of(2026, 12, 31);

        self::assertSame('2026-12-30T15:00:00+00:00', $date->atStartOfDay(new DateTimeZone('Asia/Tokyo'))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame('2027-01-01', $date->addDays(1)->toString());
        self::assertSame('2026-12-01', $date->addDays(-30)->toString());
        self::assertSame(4, $date->dayOfWeek());
    }

    public function testComparison(): void
    {
        $a = LocalDate::of(2026, 1, 2);
        $b = LocalDate::of(2026, 1, 10);

        self::assertTrue($a->isBefore($b));
        self::assertTrue($b->isAfter($a));
        self::assertTrue($a->equals(LocalDate::of(2026, 1, 2)));
        self::assertSame(-1, $a->compare($b));
    }
}
