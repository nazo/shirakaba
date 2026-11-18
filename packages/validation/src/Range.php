<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use Attribute;
use InvalidArgumentException;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Limits a number to an inclusive range.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Range implements Constraint
{
    /**
     * @throws InvalidArgumentException when the bounds are inverted
     */
    public function __construct(
        public int|float|null $min = null,
        public int|float|null $max = null,
    ) {
        Bounds::check($min, $max, allowNegative: true);
    }

    public function violation(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        return Bounds::violation($value, $this->min, $this->max, 'must be at least %s', 'must be at most %s');
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return array_filter(['minimum' => $this->min, 'maximum' => $this->max], static fn (int|float|null $value): bool => $value !== null);
    }
}
