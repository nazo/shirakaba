<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Mapping;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Shirakaba\Orm\Attribute\Computed;
use Shirakaba\Orm\Attribute\From;
use Shirakaba\Orm\Attribute\Items;
use Shirakaba\Orm\MappingError;

/**
 * How a projection class receives an entity and its loaded relations (design/08-database.md): the whole
 * entity through a parameter of its type, its properties through parameters of the same
 * names, relations requested with with() and counts requested with withCount(). #[From]
 * names another source, and #[Computed] parameters take values the caller passes.
 *
 * @internal
 */
final readonly class Projection
{
    /**
     * @param class-string $class
     * @param array<string, array{class-string, bool, bool, ?Projection, string}> $relations
     *        parameter => related entity, is a list, nullable, nested projection, relation name
     * @param array<string, string> $counts parameter => "<relation>Count"
     * @param array<string, array{string, bool}> $fields parameter => entity property, nullable
     * @param array<string, bool> $computed parameter => has a default
     */
    private function __construct(
        public string $class,
        public ?string $rootProperty,
        public array $relations,
        public array $counts = [],
        public array $fields = [],
        public array $computed = [],
    ) {}

    /**
     * @param class-string $class
     * @param EntityMap<object> $map
     * @param array<string, array<string, mixed>> $tree requested relations and their nested paths
     * @param list<string> $counts relations requested with withCount()
     * @throws MappingError when the projection and the requested relations do not match
     */
    public static function plan(string $class, EntityMap $map, array $tree, array $counts = []): self
    {
        $countProperties = array_map(static fn (string $relation): string => $relation . 'Count', $counts);
        $receivedCounts = [];
        if (!class_exists($class)) {
            throw new MappingError(sprintf('Projection %s is not a class.', $class));
        }
        $root = null;
        $relations = [];
        $fields = [];
        $computed = [];
        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $where = sprintf('Projection %s::__construct() parameter $%s', $class, $name);
            $from = ($parameter->getAttributes(From::class)[0] ?? null)?->newInstance()->name;
            if ($parameter->getAttributes(Computed::class) !== []) {
                if ($from !== null) {
                    throw new MappingError($where . ' is #[Computed], so it cannot also be #[From] an entity source.');
                }
                $computed[$name] = $parameter->isDefaultValueAvailable();
                continue;
            }
            $source = $from ?? $name;
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType) {
                throw new MappingError($where . ' needs a single declared type.');
            }
            if ($from === null && $type->getName() === $map->class && !isset($tree[$name])) {
                if ($root !== null) {
                    throw new MappingError(sprintf('Projection %s has two %s parameters.', $class, $map->class));
                }
                $root = $name;
                continue;
            }
            if (in_array($source, $countProperties, true)) {
                if ($type->getName() !== 'int') {
                    throw new MappingError(sprintf('%s receives a count, so it must be an int.', $where));
                }
                $receivedCounts[$name] = $source;
                continue;
            }
            if (!isset($tree[$source])) {
                if (!array_any($map->fields, static fn (Field $field): bool => $field->property === $source)) {
                    throw new MappingError(
                        $from === null
                            ? sprintf(
                                '%s is neither the %s, one of its properties, nor a relation requested with with(); mark values computed elsewhere #[Computed].',
                                $where,
                                $map->class,
                            ) . \Shirakaba\Orm\Internal\Messages::see('projection-mismatch')
                            : sprintf(
                                '%s is #[From(\'%s\')], which is neither a property of %s nor a relation requested with with().',
                                $where,
                                $source,
                                $map->class,
                            ) . \Shirakaba\Orm\Internal\Messages::see('projection-mismatch'),
                    );
                }
                self::checkFieldType($where, $type, $map->class, $source);
                $fields[$name] = [$source, $type->allowsNull()];
                continue;
            }
            $relation = $map->relation($source);
            $subtree = $tree[$source];
            /** @var array<string, array<string, mixed>> $subtree */
            if ($relation->isList()) {
                if ($type->getName() !== 'array') {
                    throw new MappingError(sprintf('%s receives the list relation "%s", so it must be an array.', $where, $name));
                }
                $items = ($parameter->getAttributes(Items::class)[0] ?? null)?->newInstance()->class ?? $relation->entity;
            } else {
                $items = $type->getName();
                if ($relation->kind === 'hasOne' && !$type->allowsNull()) {
                    throw new MappingError(sprintf('%s receives the has-one relation "%s", which may be missing, so it must be nullable.', $where, $name));
                }
            }
            if (!class_exists($items)) {
                throw new MappingError(sprintf('%s has no class to receive the relation "%s".', $where, $name));
            }
            $nested = null;
            if ($items !== $relation->entity) {
                $nested = self::plan($items, EntityMap::of($relation->entity), $subtree);
            } elseif ($subtree !== []) {
                throw new MappingError(sprintf(
                    '%s receives "%s" with nested relations (%s), so it needs a projection class (#[Items] for lists).',
                    $where,
                    $name,
                    implode(', ', array_keys($subtree)),
                ));
            }
            $relations[$name] = [$relation->entity, $relation->isList(), $type->allowsNull(), $nested, $source];
        }
        $missing = [
            ...array_diff(array_keys($tree), array_column($relations, 4)),
            ...array_diff($countProperties, array_values($receivedCounts)),
        ];
        if ($missing !== []) {
            throw new MappingError(
                sprintf('Projection %s has no parameter for the requested relation(s) %s.', $class, implode(', ', $missing))
                    . \Shirakaba\Orm\Internal\Messages::see('projection-mismatch'),
            );
        }

        return new self($class, $root, $relations, $receivedCounts, $fields, $computed);
    }

    /**
     * A parameter copying an entity property takes the property's type, or a wider one. A
     * nullable property (a generated key before insert) may fill a non-nullable parameter;
     * assemble() rejects the null.
     *
     * @param class-string $entity
     * @throws MappingError
     */
    private static function checkFieldType(string $where, ReflectionNamedType $type, string $entity, string $property): void
    {
        $source = new ReflectionProperty($entity, $property)->getType();
        $target = $type->getName();
        if (!$source instanceof ReflectionNamedType || $target === 'mixed') {
            return;
        }
        $name = $source->getName();
        if ($name === $target || $target === 'float' && $name === 'int' || !$source->isBuiltin() && is_a($name, $target, true)) {
            return;
        }
        throw new MappingError(sprintf('%s is %s, but %s::$%s is %s.', $where, $target, $entity, $property, $name));
    }

    /**
     * The names of #[Computed] parameters here and in nested projections.
     *
     * @return array<string, true>
     */
    public function computedNames(): array
    {
        $names = array_map(static fn (): bool => true, $this->computed);
        foreach ($this->relations as [, , , $nested]) {
            if ($nested !== null) {
                $names += $nested->computedNames();
            }
        }

        return $names;
    }

    /**
     * Entities below $entity that land in nested projections with #[Computed] parameters, so
     * one callback can compute values for every level.
     *
     * @param array<int, array<string, mixed>> $loaded
     * @param array<int, object> $found object id => entity, filled in
     */
    public function nestedComputed(object $entity, array $loaded, array &$found): void
    {
        $values = $loaded[spl_object_id($entity)] ?? [];
        foreach ($this->relations as [, , , $nested, $source]) {
            if ($nested === null) {
                continue;
            }
            $value = $values[$source] ?? null;
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (!is_object($item)) {
                    continue;
                }
                if ($nested->computed !== []) {
                    $found[spl_object_id($item)] ??= $item;
                }
                $nested->nestedComputed($item, $loaded, $found);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $loaded object id => relation => value
     * @param array<mixed> $computed #[Computed] parameter => value for this entity
     * @param array<int, array<mixed>> $nestedComputed object id => values, for nested projections
     * @throws \UnexpectedValueException when a required value is missing from the entity or its relations
     * @throws MappingError when $computed names a parameter that is not #[Computed], or leaves
     *                      out one without a default
     */
    public function assemble(object $entity, array $loaded, array $computed = [], array $nestedComputed = [], bool $strict = true): object
    {
        $arguments = $this->rootProperty === null ? [] : [$this->rootProperty => $entity];
        foreach ($computed as $name => $value) {
            if (isset($this->computed[$name])) {
                $arguments[$name] = $value;
            } elseif ($strict) {
                throw new MappingError(sprintf('Projection %s has no #[Computed] parameter $%s.', $this->class, $name));
            }
        }
        foreach ($this->computed as $name => $hasDefault) {
            if (!$hasDefault && !array_key_exists($name, $arguments)) {
                throw new MappingError(sprintf('Projection %s needs a value for the #[Computed] parameter $%s, which has no default.', $this->class, $name));
            }
        }
        foreach ($this->fields as $name => [$source, $nullable]) {
            $value = EntityMap::property($entity, $source);
            if ($value === null && !$nullable) {
                throw new \UnexpectedValueException(sprintf(
                    'Projection %s requires "%s", but the %s has none; was it stored?',
                    $this->class,
                    $source,
                    $entity::class,
                ));
            }
            $arguments[$name] = $value;
        }
        $values = $loaded[spl_object_id($entity)] ?? [];
        foreach ($this->counts as $name => $count) {
            $arguments[$name] = $values[$count] ?? 0;
        }
        $assembleNested = static fn (self $nested, object $item): object => $nested->assemble(
            $item,
            $loaded,
            $nestedComputed[spl_object_id($item)] ?? [],
            $nestedComputed,
            false,
        );
        foreach ($this->relations as $name => [, $isList, $nullable, $nested, $source]) {
            $value = $values[$source] ?? ($isList ? [] : null);
            if ($nested !== null) {
                $value = is_array($value)
                    ? array_map(static fn (object $item): object => $assembleNested($nested, $item), array_values(array_filter($value, is_object(...))))
                    : (is_object($value) ? $assembleNested($nested, $value) : null);
            }
            if ($value === null && !$nullable && !$isList) {
                throw new \UnexpectedValueException(sprintf(
                    'Projection %s requires "%s", but the related row is missing; make the parameter nullable.',
                    $this->class,
                    $name,
                ));
            }
            $arguments[$name] = $value;
        }

        return new $this->class(...$arguments);
    }
}
