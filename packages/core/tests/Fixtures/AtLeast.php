<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests\Fixtures;

use Attribute;
use Shirakaba\Core\Extraction\Constraint;

/**
 * A minimal constraint standing in for shirakaba/validation.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AtLeast implements Constraint
{
    public function __construct(
        public int $minimum,
    ) {}

    public function violation(mixed $value): ?string
    {
        return is_int($value) && $value < $this->minimum ? sprintf('must be at least %d', $this->minimum) : null;
    }

    public function keywords(): array
    {
        return ['minimum' => $this->minimum];
    }
}
