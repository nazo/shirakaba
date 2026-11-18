<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Marks a class as an entity stored in a table (design/08-database.md).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Table
{
    /**
     * @param string|null $label how API errors name the entity ("The Todo was not found.");
     *                           without it they say "resource", never the class name
     */
    public function __construct(
        public string $name,
        public ?string $label = null,
    ) {}
}
