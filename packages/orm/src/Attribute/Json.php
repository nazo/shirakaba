<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Stores an array, or a DTO mapped like a #[JsonBody] (shirakaba/json), as JSON text.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Json
{
}
