<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Set from the clock when the entity is inserted or updated.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class UpdatedAt {}
