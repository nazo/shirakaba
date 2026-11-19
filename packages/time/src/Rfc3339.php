<?php

declare(strict_types=1);

namespace Shirakaba\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Strict RFC 3339 date-times (design/07-date-and-time.md): the input must carry an offset, and output is UTC.
 *
 *     Rfc3339::parse('2026-11-11T09:30:00+09:00'); // 2026-11-11T00:30:00Z
 *     Rfc3339::parse('2026-11-11T09:30:00');       // null: no offset
 */
final readonly class Rfc3339
{
    private const string PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?([Zz]|[+-]\d{2}:\d{2})$/D';

    /**
     * Parses a date-time with an offset ("Z" or "±hh:mm") into a UTC instant.
     *
     * Rejected: a missing offset, "-00:00" (RFC 3339's "offset unknown"), a space instead of
     * "T", leap seconds, and impossible dates. Fractions beyond microseconds are truncated.
     */
    public static function parse(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }
        [, $year, $month, $day, $hour, $minute, $second] = $match;
        $fraction = $match[7];
        $offset = strtoupper($match[8]);
        if (
            !checkdate((int) $month, (int) $day, (int) $year)
            || (int) $hour > 23
            || (int) $minute > 59
            || (int) $second > 59
            || $offset === '-00:00'
            || $offset !== 'Z'
            && (
                (int) substr($offset, 1, 2) > 23
                || (int) substr($offset, 4, 2) > 59
            )
        ) {
            return null;
        }

        $micro = str_pad(substr($fraction, 0, 6), 6, '0');
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            sprintf('%s-%s-%sT%s:%s:%s.%s%s', $year, $month, $day, $hour, $minute, $second, $micro, $offset === 'Z' ? '+00:00' : $offset),
        );

        return $parsed === false ? null : $parsed->setTimezone(self::utc());
    }

    /**
     * Formats an instant in UTC with "Z" and always six fractional digits:
     * 2026-11-11T00:30:00.000000Z, 2026-11-11T00:30:00.250000Z. The fixed width makes the
     * strings sort in time order, as clients often sort ISO strings as text.
     */
    public static function format(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(self::utc())->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
