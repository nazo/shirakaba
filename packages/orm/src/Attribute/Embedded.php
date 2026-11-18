<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Stores a value object across several columns, named with a prefix ("address_" by default for $address). A nullable embedded value is null when all its columns are NULL.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Embedded
{
    public function __construct(
        public ?string $prefix = null,
    ) {}
}
