<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Mapping;

use Shirakaba\Orm\ColumnType;

/**
 * One constructor parameter of an entity and how it is stored.
 *
 * @internal
 */
final readonly class Field
{
    /**
     * @param 'value'|'wrapper'|'custom'|'json'|'embedded' $kind
     * @param string $type the PHP type for "value" (as Values understands it), or the class
     *                     for "wrapper" and "embedded"
     * @param string|null $innerType the wrapped scalar type of a "wrapper", or its property name in $wrapperProperty
     * @param list<Field> $children the fields of an "embedded" value object
     */
    public function __construct(
        public string $property,
        public string $column,
        public string $kind,
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
        public bool $id = false,
        public bool $generated = false,
        public bool $version = false,
        public bool $createdAt = false,
        public bool $updatedAt = false,
        public ?string $innerType = null,
        public ?string $wrapperProperty = null,
        public ?ColumnType $converter = null,
        public array $children = [],
    ) {}

    /**
     * The columns this field occupies.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        if ($this->kind !== 'embedded') {
            return [$this->column];
        }
        $columns = [];
        foreach ($this->children as $child) {
            foreach ($child->columns() as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
