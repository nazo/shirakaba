<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use Attribute;
use InvalidArgumentException;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Limits the length of a string in Unicode code points, as JSON Schema counts it.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Length implements Constraint
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
        if (!is_string($value)) {
            return null;
        }
        $length = preg_match_all('/./su', $value);
        if ($length === false) {
            return 'must be valid UTF-8';
        }

        return Bounds::violation($length, $this->min, $this->max, 'must be at least %s characters long', 'must be at most %s characters long');
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return array_filter(['minLength' => $this->min, 'maxLength' => $this->max], static fn (?int $value): bool => $value !== null);
    }
}
