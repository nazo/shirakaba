<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Attribute;

/**
 * Declares the item type of an array field in a DTO: `#[ListOf(Tag::class)] array $tags`.
 * PHP cannot express generic arrays, and a bare array would let anything through.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    /**
     * @param string $type "string", "int", "float", "bool", a backed enum or a DTO class
     */
    public function __construct(
        public string $type,
    ) {}
}
