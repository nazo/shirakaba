<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Marks a projection parameter whose value the application computes instead of reading it
 * from the entity, such as counts from a separate query. EntityQuery::into()
 * takes the values from its $computed callback and Entities::into() from its $computed
 * array; a parameter with a default may be left out.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Computed {}
