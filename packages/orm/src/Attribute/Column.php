<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Overrides the column name (default: the snake_case parameter name) or converts the value with a ColumnType.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Column
{
    /**
     * @param class-string<\Shirakaba\Orm\ColumnType>|null $type
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
    ) {}
}
