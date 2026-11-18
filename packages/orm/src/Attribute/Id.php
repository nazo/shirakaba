<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Marks a constructor parameter as (part of) the primary key.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Id
{
}
