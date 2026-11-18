<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use Attribute;
use InvalidArgumentException;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Restricts a scalar to a fixed set of values. Prefer a backed enum when the set is part
 * of the domain; use this for sets that exist only at the boundary.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class OneOf implements Constraint
{
    /**
     * @param non-empty-list<string|int|float|bool> $values
     * @throws InvalidArgumentException when no values are given
     */
    public function __construct(
        public array $values,
    ) {
        if ($values === []) {
            throw new InvalidArgumentException('OneOf needs at least one value.');
        }
    }

    public function violation(mixed $value): ?string
    {
        if (!is_scalar($value) || in_array($value, $this->values, true)) {
            return null;
        }

        return 'must be one of: ' . implode(', ', array_map(static fn (string|int|float|bool $item): string => var_export($item, true), $this->values));
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return ['enum' => $this->values];
    }
}
