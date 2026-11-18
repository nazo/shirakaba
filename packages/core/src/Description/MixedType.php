<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * Any value; used when an extractor accepts input it does not constrain.
 */
final readonly class MixedType implements Type
{
    public function isNullable(): bool
    {
        return true;
    }
}
