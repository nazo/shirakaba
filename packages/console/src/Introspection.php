<?php

declare(strict_types=1);

namespace Shirakaba\Console;

use Shirakaba\Core\Description\ConstType;
use Shirakaba\Core\Description\EnumType;
use Shirakaba\Core\Description\EventStreamType;
use Shirakaba\Core\Description\FileType;
use Shirakaba\Core\Description\ListType;
use Shirakaba\Core\Description\MapType;
use Shirakaba\Core\Description\MixedType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\Property;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\Type;
use Shirakaba\Core\Description\UnionType;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Turns descriptions into the text and JSON of the introspection commands. The
 * JSON shape is documented in the guide's command-line chapter and kept stable.
 */
final readonly class Introspection
{
    /**
     * A one-line name: "int", "string(date-time)", "list<Task>", "Task|null".
     */
    public static function typeName(Type $type): string
    {
        $name = match (true) {
            $type instanceof ScalarType => $type->name . ($type->format === null ? '' : '(' . $type->format . ')'),
            $type instanceof EnumType => 'enum(' . implode('|', array_map(static fn (int|string $value): string => var_export($value, true), $type->values)) . ')',
            $type instanceof ListType => 'list<' . self::typeName($type->items) . '>',
            $type instanceof MapType => 'map<string, ' . self::typeName($type->values) . '>',
            $type instanceof ObjectType => $type->name ?? self::shortName($type->class),
            $type instanceof UnionType => ($type->name ?? self::shortName($type->class)) . ' (by ' . $type->discriminator . ': ' . implode(', ', array_keys($type->members)) . ')',
            $type instanceof ConstType => var_export($type->value, true),
            $type instanceof FileType => 'file',
            $type instanceof EventStreamType => 'events(' . implode(', ', array_keys($type->events)) . ')',
            $type instanceof MixedType => 'mixed',
            default => $type::class,
        };

        return $type->isNullable() && !$type instanceof MixedType ? $name . '|null' : $name;
    }

    /**
     * The type as JSON: {"kind": ..., "nullable": ...} plus the kind's details.
     *
     * @return array<string, mixed>
     */
    public static function type(Type $type): array
    {
        $details = match (true) {
            $type instanceof ScalarType => ['kind' => $type->name, 'format' => $type->format],
            $type instanceof EnumType => ['kind' => 'enum', 'class' => $type->class, 'values' => $type->values],
            $type instanceof ListType => ['kind' => 'list', 'items' => self::type($type->items)],
            $type instanceof MapType => ['kind' => 'map', 'values' => self::type($type->values)],
            $type instanceof ObjectType => [
                'kind' => 'object',
                'class' => $type->class,
                'name' => $type->name ?? self::shortName($type->class),
                'properties' => array_map(self::property(...), $type->properties),
                'additionalProperties' => $type->additionalProperties,
            ],
            $type instanceof UnionType => [
                'kind' => 'union',
                'class' => $type->class,
                'name' => $type->name ?? self::shortName($type->class),
                'discriminator' => $type->discriminator,
                'members' => array_map(self::type(...), $type->members),
            ],
            $type instanceof ConstType => ['kind' => 'const', 'value' => $type->value],
            $type instanceof FileType => ['kind' => 'file', 'mediaTypes' => $type->mediaTypes],
            $type instanceof EventStreamType => ['kind' => 'events', 'events' => array_map(self::type(...), $type->events)],
            default => ['kind' => 'mixed'],
        };

        return [...$details, 'nullable' => $type->isNullable()];
    }

    /**
     * @param list<Constraint> $constraints
     * @return array<string, mixed> JSON Schema keywords of the constraints
     */
    public static function constraints(array $constraints): array
    {
        $keywords = [];
        foreach ($constraints as $constraint) {
            $keywords = [...$keywords, ...$constraint->keywords()];
        }

        return $keywords;
    }

    /**
     * @return array<string, mixed>
     */
    private static function property(Property $property): array
    {
        return [
            'name' => $property->name,
            'type' => self::type($property->type),
            'required' => $property->required,
            ...($property->hasDefault ? ['default' => $property->default] : []),
            ...($property->constraints === [] ? [] : ['constraints' => self::constraints($property->constraints)]),
            ...($property->description === '' ? [] : ['description' => $property->description]),
        ];
    }

    private static function shortName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
