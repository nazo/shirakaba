<?php

declare(strict_types=1);

namespace Shirakaba\Database\Dialect;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the date-time strings drivers return (design/07-date-and-time.md). Values without an offset were
 * written in UTC by Shirakaba (MySQL DATETIME, SQLite text), so they are read as UTC.
 *
 * @internal
 */
final readonly class Instants
{
    public static function parse(string $value): ?DateTimeImmutable
    {
        // Fast paths for the exact formats Shirakaba writes: SQLite text with "Z"
        // and MySQL DATETIME(6), both UTC. Other values of the same length, such as
        // PostgreSQL's "2026-11-11 11:20:33.123+00" (trailing zeros of the fraction left
        // out), take the general path.
        $length = strlen($value);
        $sqlite = $length === 27 && $value[26] === 'Z' && $value[10] === 'T';
        if ($sqlite || $length === 26 && $value[10] === ' ' && $value[19] === '.' && ctype_digit(substr($value, 20))) {
            $parsed = DateTimeImmutable::createFromFormat($sqlite ? '!Y-m-d\\TH:i:s.u\\Z' : '!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
            if ($parsed !== false && DateTimeImmutable::getLastErrors() === false) {
                return $parsed;
            }

            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}(?::?\d{2})?)?$/D', $value, $match) !== 1) {
            return null;
        }
        $offset = $match[4] ?? '';
        $offset = match (true) {
            $offset === '' || $offset === 'Z' => '+00:00',
            strlen($offset) === 3 => $offset . ':00',
            strlen($offset) === 5 => substr($offset, 0, 3) . ':' . substr($offset, 3),
            default => $offset,
        };
        $micro = str_pad(substr($match[3] ?? '', 0, 6), 6, '0');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', sprintf('%s %s.%s%s', $match[1], $match[2], $micro, $offset));
        if ($parsed === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    public static function utc(DateTimeImmutable $instant, string $format): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format($format);
    }
}
