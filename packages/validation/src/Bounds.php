<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class Bounds
{
    /**
     * @throws InvalidArgumentException when the bounds are negative (where not allowed), inverted or both missing
     */
    public static function check(int|float|null $min, int|float|null $max, bool $allowNegative): void
    {
        if ($min === null && $max === null) {
            throw new InvalidArgumentException('Give a minimum, a maximum or both.');
        }
        if (!$allowNegative && (($min !== null && $min < 0) || ($max !== null && $max < 0))) {
            throw new InvalidArgumentException('Bounds must not be negative.');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException(sprintf('The minimum %s is greater than the maximum %s.', $min, $max));
        }
    }

    public static function violation(int|float $value, int|float|null $min, int|float|null $max, string $tooSmall, string $tooLarge): ?string
    {
        return match (true) {
            $min !== null && $value < $min => sprintf($tooSmall, $min),
            $max !== null && $value > $max => sprintf($tooLarge, $max),
            default => null,
        };
    }
}
