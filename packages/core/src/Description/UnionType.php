<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * One of several object types, told apart by a discriminator property whose value names the
 * member (a tagged union, OpenAPI oneOf with a discriminator).
 */
final readonly class UnionType implements Type
{
    /**
     * @param class-string $class the type the members share, usually an interface
     * @param non-empty-array<string, ObjectType> $members discriminator value => member type
     */
    public function __construct(
        public string $class,
        public string $discriminator,
        public array $members,
        public bool $nullable = false,
        /** The public name of the type (for schema names); the short class name when null. */
        public ?string $name = null,
        /** Whether this describes output the application writes (see ObjectType::$output). */
        public bool $output = false,
    ) {}

    public function isNullable(): bool
    {
        return $this->nullable;
    }
}
