<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Mapping;

use Shirakaba\Database\Reader;
use Shirakaba\Orm\EntityQuery;
use Shirakaba\Orm\MappingError;
use Shirakaba\Orm\Scope;
use Shirakaba\Query\Conditions;
use Shirakaba\Query\Select;

/**
 * Loads requested relations with one batched query per relation and level (design/08-database.md):
 * `WHERE key IN (...)`, chunked, never one query per row. Belongs-to relations at the leaves
 * and many-to-many relations without nested relations or a withWhere() constraint are loaded
 * last: one join-table query per relation and one query per related entity class for all
 * paths. with('user', 'reply.user') reads users once, and
 * with('files', 'reply.files') reads the join table once and the files once.
 *
 * @internal
 */
final readonly class Loader
{
    private const int CHUNK = 1000;

    /**
     * Counts list relations per owner with one grouped query each, stored as "<name>Count".
     *
     * @param EntityMap<object> $map
     * @param list<object> $owners
     * @param list<string> $relations
     * @param array<int, array<string, mixed>> $loaded
     * @param EntityQuery<object> $context
     */
    public static function count(EntityMap $map, array $owners, array $relations, Reader $db, array &$loaded, EntityQuery $context): void
    {
        if ($owners === [] || $relations === []) {
            return;
        }
        $id = self::singleId($map);
        $keys = [];
        foreach ($owners as $index => $owner) {
            $keys[$index] = $map->databaseValue($id, EntityMap::property($owner, $id->property));
        }
        foreach ($relations as $name) {
            $relation = $map->relation($name);
            $related = EntityMap::of($relation->entity);
            [$scopes, $without] = $context->scopesFor($related);
            $counts = [];
            foreach (array_chunk(array_values(array_unique($keys, SORT_REGULAR)), self::CHUNK) as $chunk) {
                if ($relation->kind === 'manyToMany') {
                    $through = $relation->through ?? '';
                    $local = $relation->localKey ?? '';
                    $relatedId = self::singleId($related);
                    $select = EntityQuery::of($related->class)->scoped(...$scopes)->withoutScope(...$without)->select()
                        ->join($through, $through . '.' . $relation->foreignKey, '=', $related->table . '.' . $relatedId->column)
                        ->whereIn($through . '.' . $local, $chunk);
                    $group = $through . '.' . $local;
                } else {
                    $foreign = $related->table . '.' . $related->field($relation->foreignKey)->column;
                    $select = EntityQuery::of($related->class)->scoped(...$scopes)->withoutScope(...$without)->select()->whereIn($foreign, $chunk);
                    $group = $foreign;
                }
                $rows = $select->reselect()
                    ->column($group, 'owner_key')
                    ->column(\Shirakaba\Query\Sql::raw('COUNT(*)'), 'total')
                    ->groupBy($group)
                    ->fetchAll($db);
                foreach ($rows as $row) {
                    $total = $row['total'] ?? 0;
                    $counts[self::key($row['owner_key'] ?? null)] = is_int($total) || is_string($total) ? (int) $total : 0;
                }
            }
            foreach ($owners as $index => $owner) {
                $loaded[spl_object_id($owner)][$name . 'Count'] = $counts[self::key($keys[$index] ?? null)] ?? 0;
            }
        }
    }

    /**
     * @param EntityMap<object> $map
     * @param list<object> $owners entities of $map
     * @param array<string, array<string, mixed>> $tree
     * @param array<int, array<string, mixed>> $loaded object id => relation => value, filled in
     * @param EntityQuery<object>|null $context the root query, whose scopes apply to related entities
     */
    public static function load(EntityMap $map, array $owners, array $tree, Reader $db, array &$loaded, ?EntityQuery $context = null): void
    {
        $deferred = [];
        self::walk($map, $owners, $tree, $db, $loaded, $context, '', $deferred);
        foreach ($deferred as $class => $entries) {
            $related = EntityMap::of($class);
            $values = self::deferred($entries, $related, $db, [...($context?->scopesFor($related) ?? [[], []]), null]);
            foreach ($entries as $entry => [, , $owners, $name]) {
                foreach ($owners as $index => $owner) {
                    $loaded[spl_object_id($owner)][$name] = $values[$entry][$index] ?? null;
                }
            }
        }
    }

    /**
     * @param EntityMap<object> $map
     * @param list<object> $owners
     * @param array<string, array<string, mixed>> $tree
     * @param array<int, array<string, mixed>> $loaded
     * @param EntityQuery<object>|null $context
     * @param array<class-string, list<array{EntityMap<object>, Relation, list<object>, string}>> $deferred
     *        related class => leaf belongs-to loads to merge
     */
    private static function walk(EntityMap $map, array $owners, array $tree, Reader $db, array &$loaded, ?EntityQuery $context, string $prefix, array &$deferred): void
    {
        foreach ($tree as $name => $subtree) {
            $path = $prefix . $name;
            /** @var array<string, array<string, mixed>> $subtree */
            $relation = $map->relation($name);
            $related = EntityMap::of($relation->entity);
            $constraint = $context?->constraintFor($path);
            if (in_array($relation->kind, ['belongsTo', 'manyToMany'], true) && $subtree === [] && $constraint === null) {
                // Nothing below it and no filter: safe to share one query with other paths.
                $deferred[$related->class][] = [$map, $relation, $owners, $name];
                continue;
            }
            $scope = [...($context?->scopesFor($related) ?? [[], []]), $constraint];
            $values = match ($relation->kind) {
                'belongsTo' => self::belongsTo($map, $related, $relation, $owners, $db, $scope),
                'hasOne', 'hasMany' => self::hasMany($map, $related, $relation, $owners, $db, $scope),
                'manyToMany' => self::manyToMany($map, $related, $relation, $owners, $db, $scope),
            };
            $children = [];
            foreach ($owners as $index => $owner) {
                $value = $values[$index] ?? ($relation->isList() ? [] : null);
                $loaded[spl_object_id($owner)][$name] = $value;
                foreach (is_array($value) ? $value : [$value] as $child) {
                    if (is_object($child)) {
                        $children[spl_object_id($child)] = $child;
                    }
                }
            }
            if ($subtree !== [] && $children !== []) {
                self::walk($related, array_values($children), $subtree, $db, $loaded, $context, $path . '.', $deferred);
            }
        }
    }

    /**
     * @param EntityMap<object> $map
     * @param EntityMap<object> $related
     * @param array{list<Scope>, list<class-string<Scope>>, (\Closure(EntityQuery<object>): EntityQuery<object>)|null} $scope
     * @param list<object> $owners
     * @return array<int, object|null>
     */
    private static function belongsTo(EntityMap $map, EntityMap $related, Relation $relation, array $owners, Reader $db, array $scope): array
    {
        return self::belongsToMany([[$map, $relation, $owners, $relation->name]], $related, $db, $scope)[0] ?? [];
    }

    /**
     * Loads deferred belongs-to and many-to-many relations that point at the same entity
     * class: one join-table query per many-to-many relation for all its owners, then one query
     * for every related entity any of them needs.
     *
     * @param list<array{EntityMap<object>, Relation, list<object>, string}> $entries
     * @param EntityMap<object> $related
     * @param array{list<Scope>, list<class-string<Scope>>, (\Closure(EntityQuery<object>): EntityQuery<object>)|null} $scope
     * @return array<int, array<int, object|list<object>|null>> entry => owner index => value
     */
    private static function deferred(array $entries, EntityMap $related, Reader $db, array $scope): array
    {
        $relatedId = self::singleId($related);
        $wanted = [];
        $ownerKeys = [];
        /** @var array<string, array{EntityMap<object>, Relation, array<string, mixed>}> $joins relation => map, relation, owner keys */
        $joins = [];
        foreach ($entries as $entry => [$map, $relation, $owners]) {
            if ($relation->kind === 'manyToMany') {
                $id = self::singleId($map);
                $signature = $map->class . '#' . $relation->name;
                $joins[$signature] ??= [$map, $relation, []];
                foreach ($owners as $index => $owner) {
                    $key = $map->databaseValue($id, EntityMap::property($owner, $id->property));
                    $ownerKeys[$entry][$index] = $key;
                    $joins[$signature][2][self::key($key)] = $key;
                }
                continue;
            }
            $key = $map->field($relation->foreignKey);
            foreach ($owners as $index => $owner) {
                $value = $map->databaseValue($key, EntityMap::property($owner, $key->property));
                $ownerKeys[$entry][$index] = $value;
                if ($value !== null) {
                    $wanted[self::key($value)] = $value;
                }
            }
        }
        /** @var array<string, array<string, list<mixed>>> $targets relation => owner key => related keys in order */
        $targets = [];
        foreach ($joins as $signature => [, $relation, $keys]) {
            foreach (self::pairs($relation, array_values($keys), $db) as [$owner, $target]) {
                $targets[$signature][self::key($owner)][] = $target;
                $wanted[self::key($target)] = $target;
            }
        }
        $byId = [];
        foreach (self::fetch($related, $relatedId->column, array_values($wanted), $db, $scope) as [$row, $entity]) {
            $byId[self::key($row[$relatedId->column] ?? null)] = $entity;
        }
        $values = [];
        foreach ($entries as $entry => [$map, $relation]) {
            foreach ($ownerKeys[$entry] ?? [] as $index => $value) {
                if ($relation->kind === 'manyToMany') {
                    $list = [];
                    foreach ($targets[$map->class . '#' . $relation->name][self::key($value)] ?? [] as $target) {
                        $entity = $byId[self::key($target)] ?? null;
                        if ($entity !== null) {
                            $list[] = $entity;
                        }
                    }
                    $values[$entry][$index] = $list;
                } else {
                    $values[$entry][$index] = $value === null ? null : ($byId[self::key($value)] ?? null);
                }
            }
        }

        return $values;
    }

    /**
     * The join-table rows of a many-to-many relation for the given owner keys, in the order
     * of its orderBy column.
     *
     * @param list<mixed> $keys
     * @return list<array{mixed, mixed}> owner key, related key
     */
    private static function pairs(Relation $relation, array $keys, Reader $db): array
    {
        $through = $relation->through ?? throw new MappingError(sprintf('Relation "%s" has no join table.', $relation->name));
        $local = $relation->localKey ?? throw new MappingError(sprintf('Relation "%s" has no local key.', $relation->name));
        $pairs = [];
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $select = Select::from($through)->columns($local, $relation->foreignKey)->whereIn($local, $chunk);
            $rows = ($relation->orderBy === null ? $select : $select->orderBy($relation->orderBy))->fetchAll($db);
            foreach ($rows as $row) {
                $pairs[] = [$row[$local] ?? null, $row[$relation->foreignKey] ?? null];
            }
        }

        return $pairs;
    }

    /**
     * Loads belongs-to relations of several owner groups that point at the same entity class,
     * with one query (chunked) for all of them.
     *
     * @param list<array{EntityMap<object>, Relation, list<object>, string}> $entries
     * @param EntityMap<object> $related
     * @param array{list<Scope>, list<class-string<Scope>>, (\Closure(EntityQuery<object>): EntityQuery<object>)|null} $scope
     * @return array<int, array<int, object|null>> entry => owner index => related entity
     */
    private static function belongsToMany(array $entries, EntityMap $related, Reader $db, array $scope): array
    {
        $id = self::singleId($related);
        $keys = [];
        $all = [];
        foreach ($entries as $entry => [$map, $relation, $owners]) {
            $key = $map->field($relation->foreignKey);
            foreach ($owners as $index => $owner) {
                $value = $map->databaseValue($key, EntityMap::property($owner, $key->property));
                $keys[$entry][$index] = $value;
                if ($value !== null) {
                    $all[self::key($value)] = $value;
                }
            }
        }
        $byId = [];
        foreach (self::fetch($related, $id->column, array_values($all), $db, $scope) as [$row, $entity]) {
            $byId[self::key($row[$id->column] ?? null)] = $entity;
        }
        $values = [];
        foreach ($keys as $entry => $ownerKeys) {
            foreach ($ownerKeys as $index => $value) {
                $values[$entry][$index] = $value === null ? null : ($byId[self::key($value)] ?? null);
            }
        }

        return $values;
    }

    /**
     * @param EntityMap<object> $map
     * @param EntityMap<object> $related
     * @param array{list<Scope>, list<class-string<Scope>>, (\Closure(EntityQuery<object>): EntityQuery<object>)|null} $scope
     * @param list<object> $owners
     * @return array<int, object|list<object>|null>
     */
    private static function hasMany(EntityMap $map, EntityMap $related, Relation $relation, array $owners, Reader $db, array $scope): array
    {
        $id = self::singleId($map);
        $foreign = $related->field($relation->foreignKey);
        $keys = [];
        foreach ($owners as $index => $owner) {
            $keys[$index] = $map->databaseValue($id, EntityMap::property($owner, $id->property));
        }
        $order = $relation->orderBy === null ? null : $related->field($relation->orderBy)->column;
        $groups = [];
        foreach (self::fetch($related, $foreign->column, array_values(array_unique($keys, SORT_REGULAR)), $db, $scope, $order) as [$row, $entity]) {
            $groups[self::key($row[$foreign->column] ?? null)][] = $entity;
        }
        $values = [];
        foreach ($keys as $index => $value) {
            $group = $groups[self::key($value)] ?? [];
            $values[$index] = $relation->kind === 'hasOne' ? ($group[0] ?? null) : $group;
        }

        return $values;
    }

    /**
     * @param EntityMap<object> $map
     * @param EntityMap<object> $related
     * @param array{list<Scope>, list<class-string<Scope>>, (\Closure(EntityQuery<object>): EntityQuery<object>)|null} $scope
     * @param list<object> $owners
     * @return array<int, list<object>>
     */
    private static function manyToMany(EntityMap $map, EntityMap $related, Relation $relation, array $owners, Reader $db, array $scope): array
    {
        $id = self::singleId($map);
        $relatedId = self::singleId($related);
        $keys = [];
        foreach ($owners as $index => $owner) {
            $keys[$index] = $map->databaseValue($id, EntityMap::property($owner, $id->property));
        }
        $pairs = self::pairs($relation, array_values(array_unique($keys, SORT_REGULAR)), $db);
        $byId = [];
        foreach (self::fetch($related, $relatedId->column, array_values(array_unique(array_column($pairs, 1), SORT_REGULAR)), $db, $scope) as [$row, $entity]) {
            $byId[self::key($row[$relatedId->column] ?? null)] = $entity;
        }
        $groups = [];
        foreach ($pairs as [$owner, $target]) {
            $entity = $byId[self::key($target)] ?? null;
            if ($entity !== null) {
                $groups[self::key($owner)][] = $entity;
            }
        }
        $values = [];
        foreach ($keys as $index => $value) {
            $values[$index] = $groups[self::key($value)] ?? [];
        }

        return $values;
    }

    /**
     * @param EntityMap<object> $map
     * @param list<mixed> $keys
     * @param array{list<Scope>, list<class-string<Scope>>, (\Closure(EntityQuery<object>): EntityQuery<object>)|null} $scope
     * @return list<array{array<string, mixed>, object}>
     */
    private static function fetch(EntityMap $map, string $column, array $keys, Reader $db, array $scope, ?string $order = null): array
    {
        $result = [];
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $without = $scope[1];
            $entityQuery = EntityQuery::of($map->class)->scoped(...$scope[0])->withoutScope(...$without);
            if ($scope[2] !== null) {
                $entityQuery = ($scope[2])($entityQuery);
            }
            $query = $entityQuery
                ->whereColumns(static fn (Conditions $c): Conditions => $c->whereIn($map->table . '.' . $column, $chunk))
                ->select();
            if ($order !== null) {
                $query = $query->orderBy($map->table . '.' . $order);
            }
            foreach ($query->fetchAll($db) as $row) {
                $result[] = [$row, $map->hydrate($row, $db->dialect())];
            }
        }

        return $result;
    }

    /**
     * @param EntityMap<object> $map
     */
    private static function singleId(EntityMap $map): Field
    {
        $ids = $map->ids();
        if (count($ids) !== 1) {
            throw new MappingError(sprintf('%s needs exactly one #[Id] to be the target of a relation.', $map->class));
        }

        return $ids[0];
    }

    /**
     * Keys compare as strings, since drivers return integers as ints or numeric strings.
     */
    private static function key(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            default => '',
        };
    }
}
