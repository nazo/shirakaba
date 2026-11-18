<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * A structured value with named properties, such as a DTO mapped from a JSON object.
 */
final readonly class ObjectType implements Type
{
    /**
     * @param class-string $class
     * @param list<Property> $properties
     */
    public function __construct(
        public string $class,
        public array $properties,
        /** Whether properties other than the listed ones are accepted. */
        public bool $additionalProperties = false,
        public bool $nullable = false,
        /** The public name of the type (for schema names); the short class name when null. */
        public ?string $name = null,
        /**
         * Whether this describes output the application writes, where every property is
         * present, rather than input it accepts, where properties with defaults may be left out.
         */
        public bool $output = false,
    ) {}

    /**
     * The same type as written in responses: every object property is required, because
     * serialisers write all of them.
     */
    public static function forOutput(Type $type): Type
    {
        return match (true) {
            $type instanceof self => $type->asOutput(),
            $type instanceof UnionType => new UnionType(
                $type->class,
                $type->discriminator,
                array_map(static fn (self $member): self => $member->asOutput(), $type->members),
                $type->nullable,
                $type->name,
                true,
            ),
            $type instanceof ListType => new ListType(self::forOutput($type->items), $type->nullable),
            $type instanceof MapType => new MapType(self::forOutput($type->values), $type->nullable),
            default => $type,
        };
    }

    private function asOutput(): self
    {
        return new self(
            $this->class,
            array_map(static fn (Property $property): Property => new Property(
                $property->name,
                self::forOutput($property->type),
                required: true,
                hasDefault: false,
                constraints: $property->constraints,
                description: $property->description,
            ), $this->properties),
            $this->additionalProperties,
            $this->nullable,
            $this->name,
            true,
        );
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }
}
