<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Attribute;

/**
 * Names a DTO field differently in JSON, in requests and in described responses:
 * `#[JsonName('per_page')] int $perPage`.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class JsonName
{
    public function __construct(
        public string $name,
    ) {}
}
