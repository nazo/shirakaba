<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Attribute;

/**
 * Declares the value type of an Optional DTO field (design/06-json-and-openapi.md).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class OptionalOf
{
    /**
     * @param string $type "string", "int", "float", "bool", a backed enum or a DTO class
     * @param bool $nullable whether null may be sent
     */
    public function __construct(
        public string $type,
        public bool $nullable = false,
    ) {}
}
