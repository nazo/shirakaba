<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * The database generates the value (auto-increment, identity or a column default). The parameter must be nullable with a null default; inserts leave it out when null and return the entity with the generated value.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Generated {}
