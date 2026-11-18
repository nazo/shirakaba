<?php

declare(strict_types=1);

namespace Shirakaba\Time\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Time\Rfc3339;

#[CoversClass(Rfc3339::class)]
final class Rfc3339Test extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valid(): iterable
    {
        yield 'utc' => ['2026-11-11T00:30:00Z', '2026-11-11T00:30:00.000000Z'];
        yield 'offset' => ['2026-11-11T09:30:00+09:00', '2026-11-11T00:30:00.000000Z'];
        yield 'negative offset crossing a day' => ['2026-11-10T20:00:00-05:00', '2026-11-11T01:00:00.000000Z'];
        yield 'lower case' => ['2026-11-11t00:30:00z', '2026-11-11T00:30:00.000000Z'];
        yield 'milliseconds' => ['2026-11-11T00:30:00.250Z', '2026-11-11T00:30:00.250000Z'];
        yield 'nanoseconds truncated' => ['2026-11-11T00:30:00.123456789Z', '2026-11-11T00:30:00.123456Z'];
        yield 'leap day' => ['2028-02-29T00:00:00Z', '2028-02-29T00:00:00.000000Z'];
    }

    #[DataProvider('valid')]
    public function testParsesAndFormatsInUtc(string $input, string $expected): void
    {
        $parsed = Rfc3339::parse($input);

        self::assertNotNull($parsed);
        self::assertSame('UTC', $parsed->getTimezone()->getName());
        self::assertSame($expected, Rfc3339::format($parsed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'no offset' => ['2026-11-11T09:30:00'];
        yield 'unknown offset' => ['2026-11-11T09:30:00-00:00'];
        yield 'space separator' => ['2026-11-11 09:30:00Z'];
        yield 'date only' => ['2026-11-11'];
        yield 'impossible date' => ['2026-02-30T00:00:00Z'];
        yield 'hour 24' => ['2026-11-11T24:00:00Z'];
        yield 'leap second' => ['2026-12-31T23:59:60Z'];
        yield 'offset out of range' => ['2026-11-11T00:00:00+24:00'];
        yield 'short offset' => ['2026-11-11T00:00:00+0900'];
        yield 'trailing text' => ["2026-11-11T00:00:00Z\n"];
        yield 'empty fraction' => ['2026-11-11T00:00:00.Z'];
    }

    #[DataProvider('invalid')]
    public function testRejects(string $input): void
    {
        self::assertNull(Rfc3339::parse($input));
    }

    public function testFormatsAnyTimezoneAsUtc(): void
    {
        $tokyo = new DateTimeImmutable('2026-11-11 09:30:00', new DateTimeZone('Asia/Tokyo'));

        self::assertSame('2026-11-11T00:30:00.000000Z', Rfc3339::format($tokyo));
    }
}
