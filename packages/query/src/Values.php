<?php

declare(strict_types=1);

namespace Shirakaba\Query;

use BackedEnum;
use DateTimeImmutable;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Time\LocalDate;
use ReflectionEnum;

/**
 * Converts a non-null column value, as the driver returned it, to a PHP type (design/07-date-and-time.md):
 * numeric strings to numbers, 0/1 and t/f to booleans, date-times through the dialect in
 * UTC, "YYYY-MM-DD" to LocalDate, backing values to enum cases.
 */
final readonly class Values
{
    /**
     * Types read() understands.
     */
    public static function supports(string $type): bool
    {
        return in_array($type, ['int', 'float', 'bool', 'string', 'mixed', DateTimeImmutable::class, LocalDate::class], true)
            || (enum_exists($type) && is_subclass_of($type, BackedEnum::class));
    }

    /**
     * @return mixed the converted value, or null when $value is not valid for $type
     */
    public static function read(mixed $value, string $type, Dialect $dialect): mixed
    {
        return match ($type) {
            'mixed' => $value,
            'int' => match (true) {
                is_int($value) => $value,
                is_bool($value) => (int) $value,
                is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 && (string) (int) $value === $value => (int) $value,
                default => null,
            },
            'float' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null,
            'bool' => match (true) {
                is_bool($value) => $value,
                $value === 1, $value === '1', $value === 't', $value === 'true' => true,
                $value === 0, $value === '0', $value === 'f', $value === 'false' => false,
                default => null,
            },
            'string' => is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : null),
            DateTimeImmutable::class => $value instanceof DateTimeImmutable ? $value : (is_string($value) ? $dialect->parseInstant($value) : null),
            LocalDate::class => $value instanceof LocalDate ? $value : (is_string($value) ? LocalDate::parse(substr($value, 0, 10)) : null),
            default => self::enum($type, $value),
        };
    }

    private static function enum(string $type, mixed $value): ?BackedEnum
    {
        if (!enum_exists($type) || !is_subclass_of($type, BackedEnum::class)) {
            return null;
        }
        if ($value instanceof $type) {
            return $value;
        }
        if ((string) new ReflectionEnum($type)->getBackingType() === 'int') {
            return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) ? $type::tryFrom((int) $value) : null;
        }

        return is_string($value) || is_int($value) ? $type::tryFrom((string) $value) : null;
    }
}
