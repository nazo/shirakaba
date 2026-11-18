<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Attribute;

/**
 * Declares an array field of a DTO as a JSON object with arbitrary keys:
 * `#[MapOf('int')] array $reactions` is `{"👍": 2, "🎉": 1}`. Json always writes it as an
 * object, `{}` when empty, and OpenAPI describes it with additionalProperties.
 * Keys that look like integers arrive as int keys, as everywhere in PHP arrays, so declare
 * the field `array<array-key, T>`.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class MapOf
{
    /**
     * @param string $type the value type: "string", "int", "float", "bool", a backed enum or a DTO class
     */
    public function __construct(
        public string $type,
    ) {}
}
