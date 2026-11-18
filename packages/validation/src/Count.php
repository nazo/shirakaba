<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use Attribute;
use InvalidArgumentException;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Limits the number of items in a list.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Count implements Constraint
{
    /**
     * @throws InvalidArgumentException when the bounds are negative or inverted
     */
    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
    ) {
        Bounds::check($min, $max, allowNegative: false);
    }

    public function violation(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        return Bounds::violation(count($value), $this->min, $this->max, 'must have at least %s items', 'must have at most %s items');
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return array_filter(['minItems' => $this->min, 'maxItems' => $this->max], static fn (?int $value): bool => $value !== null);
    }
}
