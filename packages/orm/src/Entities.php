<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use DateTimeImmutable;
use LogicException;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\Orm\Mapping\EntityMap;
use Shirakaba\Orm\Mapping\Field;
use Shirakaba\Orm\Mapping\Loader;
use Shirakaba\Orm\Mapping\Projection;
use Shirakaba\Query\Delete;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;
use Shirakaba\Query\Update;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Reads and explicit writes of entities (design/08-database.md). There is no unit of work: every write
 * is one statement the caller asks for, and entities are immutable values.
 *
 *     $task = Entities::get(Task::class, $id, $reader);
 *     $task = Entities::insert(new Task(null, 'Write docs'), $writer);
 *     $task = Entities::update(Entities::change($task, ['done' => true]), $writer);
 *     Entities::patch(Task::class, $id, ['done' => true], $writer);
 *     $view = Entities::into($task, TaskView::class);
 */
final readonly class Entities
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @return EntityQuery<T>
     */
    public static function query(string $class): EntityQuery
    {
        return EntityQuery::of($class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param mixed $id the primary key value, or property => value for composite keys
     * @param list<Scope> $scopes scopes the entity requires
     * @return T|null
     * @throws DatabaseError
     */
    public static function find(string $class, mixed $id, Reader $db, array $scopes = []): ?object
    {
        $query = EntityQuery::of($class)->scoped(...$scopes);
        foreach (self::keyValues(EntityMap::of($class), $id) as $property => $value) {
            $query = $query->where($property, '=', $value);
        }

        return $query->first($db);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param list<Scope> $scopes scopes the entity requires
     * @return T
     * @throws EntityNotFound (a 404) when there is no such row
     * @throws DatabaseError
     */
    public static function get(string $class, mixed $id, Reader $db, array $scopes = []): object
    {
        return self::find($class, $id, $db, $scopes) ?? throw EntityNotFound::of(EntityMap::of($class)->label);
    }

    /**
     * Inserts an entity and returns it with generated values, timestamps and the initial
     * version filled in.
     *
     * An entity with a #[RequiredScope] needs that scope in $scopes; a scope that
     * implements ScopeOnInsert must admit the entity, so a forged tenant id is refused.
     *
     * @template T of object
     * @param T $entity
     * @param list<Scope> $scopes
     * @return T
     * @throws ScopeMissing when a required scope is not passed
     * @throws ScopeViolation when a scope does not admit the entity
     * @throws DatabaseError
     */
    public static function insert(object $entity, Writer $db, ClockInterface $clock = new SystemClock(), array $scopes = []): object
    {
        $map = EntityMap::of($entity::class);
        self::checkInsert($map, $entity, $scopes);
        $entity = self::stamp($map, $entity, $clock, inserting: true);
        $values = $map->dehydrate($entity);
        $generated = [];
        foreach ($map->fields as $field) {
            if ($field->generated && ($values[$field->column] ?? null) === null) {
                unset($values[$field->column]);
                $generated[] = $field;
            }
        }

        $insert = Insert::into($map->table)->values($values);
        if ($generated === []) {
            $insert->execute($db);

            return $entity;
        }
        if ($db->dialect()->supportsReturning()) {
            $row = $insert->returning(...array_map(static fn (Field $field): string => $field->column, $generated))->executeReturning($db)[0] ?? [];
        } else {
            $insert->execute($db);
            $row = self::generatedAfterInsert($map, $generated, $values, $db);
        }

        return self::rebuild($map, $entity, $row, $db);
    }

    /**
     * Inserts many entities with multi-row statements. Generated values are not read back;
     * use insert() for entities whose generated key you need.
     *
     * @param list<object> $entities all of one class
     * @param list<Scope> $scopes required scopes, checked as in insert()
     * @return int inserted rows
     * @throws ScopeMissing when a required scope is not passed
     * @throws ScopeViolation when a scope does not admit an entity
     * @throws DatabaseError
     */
    public static function insertAll(array $entities, Writer $db, ClockInterface $clock = new SystemClock(), int $chunk = 500, array $scopes = []): int
    {
        $first = $entities[0] ?? null;
        if ($first === null) {
            return 0;
        }
        $map = EntityMap::of($first::class);
        $rows = [];
        foreach ($entities as $entity) {
            if ($entity::class !== $map->class) {
                throw new LogicException('insertAll() takes entities of one class.');
            }
            self::checkInsert($map, $entity, $scopes);
            $values = $map->dehydrate(self::stamp($map, $entity, $clock, inserting: true));
            foreach ($map->fields as $field) {
                if ($field->generated && ($values[$field->column] ?? null) === null) {
                    unset($values[$field->column]);
                }
            }
            $rows[] = $values;
        }
        $inserted = 0;
        foreach (array_chunk($rows, max(1, $chunk)) as $batch) {
            $inserted += Insert::into($map->table)->rows($batch)->execute($db);
        }

        return $inserted;
    }

    /**
     * @param EntityMap<object> $map
     * @param list<Scope> $scopes
     * @throws ScopeMissing
     * @throws ScopeViolation
     */
    private static function checkInsert(EntityMap $map, object $entity, array $scopes): void
    {
        foreach ($map->requiredScopes as $required) {
            $given = array_values(array_filter($scopes, static fn (Scope $scope): bool => $scope instanceof $required));
            if ($given === []) {
                throw ScopeMissing::onInsert($map->class, $required);
            }
        }
        foreach ($scopes as $scope) {
            if ($scope instanceof ScopeOnInsert && !$scope->admits($entity)) {
                throw ScopeViolation::of($map->label ?? 'entity', $scope::class);
            }
        }
    }

    /**
     * Writes every mapped column of the entity by primary key and returns the entity with a
     * new version and update timestamp. A #[Version]ed entity that changed since it was read
     * fails with StaleEntity (a 409).
     *
     * Scopes apply like in queries: an entity with a #[RequiredScope] is only
     * written when $scopes contains it, so a forged key cannot reach another tenant's row.
     * Columns changed concurrently by another request are overwritten; use patch() for
     * updates of a few columns.
     *
     * @template T of object
     * @param T $entity
     * @param list<Scope> $scopes
     * @return T
     * @throws StaleEntity
     * @throws ScopeMissing
     * @throws DatabaseError
     */
    public static function update(object $entity, Writer $db, ClockInterface $clock = new SystemClock(), array $scopes = []): object
    {
        $map = EntityMap::of($entity::class);
        $entity = self::stamp($map, $entity, $clock, inserting: false);
        $values = $map->dehydrate($entity);
        $assignments = [];
        foreach ($map->fields as $field) {
            if ($field->id || $field->createdAt || $field->generated || $field->version) {
                continue;
            }
            foreach ($field->columns() as $column) {
                $assignments[$column] = $values[$column] ?? null;
            }
        }
        $query = self::byKey($map, self::idOf($map, $entity), $scopes);
        $version = self::versionField($map);
        $current = null;
        if ($version !== null) {
            $current = EntityMap::property($entity, $version->property);
            $query = $query->where($version->property, '=', $current);
        }
        $changed = $query->updateColumns($assignments, $db, $clock);
        if ($version === null) {
            return $entity;
        }
        if ($changed === 0) {
            throw StaleEntity::of($map->label);
        }

        return self::rebuild($map, $entity, [$version->column => (is_int($current) ? $current : 0) + 1], $db);
    }

    /**
     * Writes only the named properties of one row, without loading it. Scopes apply as in
     * update().
     *
     * @param class-string $class
     * @param array<string, mixed> $changes property => value
     * @param list<Scope> $scopes
     * @return int affected rows (on MySQL, rows actually changed)
     * @throws ScopeMissing
     * @throws DatabaseError
     */
    public static function patch(string $class, mixed $id, array $changes, Writer $db, ClockInterface $clock = new SystemClock(), array $scopes = []): int
    {
        if ($changes === []) {
            return 0;
        }

        return self::byKey(EntityMap::of($class), $id, $scopes)->update($changes, $db, $clock);
    }

    /**
     * Deletes the entity's row, or for a #[SoftDelete] entity sets its deletion time. A
     * #[Version]ed entity must not have changed since it was read. Scopes apply as in update().
     *
     * @param list<Scope> $scopes
     * @return bool whether a row was deleted (or marked deleted)
     * @throws StaleEntity
     * @throws ScopeMissing
     * @throws DatabaseError
     */
    public static function delete(object $entity, Writer $db, ClockInterface $clock = new SystemClock(), array $scopes = []): bool
    {
        $map = EntityMap::of($entity::class);
        if ($map->softDelete !== null) {
            return self::patch($map->class, self::idOf($map, $entity), [$map->softDelete => self::now($clock)], $db, $clock, $scopes) > 0;
        }

        return self::forceDelete($entity, $db, $scopes);
    }

    /**
     * Clears the deletion time of a #[SoftDelete] entity. Scopes apply as in update().
     *
     * @param list<Scope> $scopes
     * @return bool whether a row was restored
     * @throws ScopeMissing
     * @throws DatabaseError
     */
    public static function restore(object $entity, Writer $db, ClockInterface $clock = new SystemClock(), array $scopes = []): bool
    {
        $map = EntityMap::of($entity::class);
        if ($map->softDelete === null) {
            throw new LogicException(sprintf('%s is not #[SoftDelete].', $map->class));
        }

        return self::byKey($map, self::idOf($map, $entity), $scopes)->withoutScope(NotDeleted::class)->update([$map->softDelete => null], $db, $clock) > 0;
    }

    /**
     * Deletes the row, even of a #[SoftDelete] entity. Scopes apply as in update().
     *
     * @param list<Scope> $scopes
     * @return bool whether a row was deleted
     * @throws StaleEntity
     * @throws ScopeMissing
     * @throws DatabaseError
     */
    public static function forceDelete(object $entity, Writer $db, array $scopes = []): bool
    {
        $map = EntityMap::of($entity::class);
        $query = self::byKey($map, self::idOf($map, $entity), $scopes)->withoutScope(NotDeleted::class);
        $version = self::versionField($map);
        if ($version !== null) {
            $query = $query->where($version->property, '=', EntityMap::property($entity, $version->property));
        }
        $deleted = $query->forceDelete($db);
        if ($deleted === 0 && $version !== null) {
            throw StaleEntity::of($map->label);
        }

        return $deleted > 0;
    }

    /**
     * The query for one row by key, with the entity's scopes.
     *
     * @param EntityMap<object> $map
     * @param list<Scope> $scopes
     * @return EntityQuery<object>
     */
    private static function byKey(EntityMap $map, mixed $id, array $scopes): EntityQuery
    {
        $query = EntityQuery::of($map->class)->scoped(...$scopes);
        foreach (self::keyValues($map, $id) as $property => $value) {
            $query = $query->where($property, '=', $value);
        }

        return $query;
    }

    /**
     * Inserts the entity, or overwrites its other columns when a row with the same values in
     * $conflict (properties forming a unique key) exists.
     *
     * @param list<string> $conflict
     * @throws DatabaseError
     */
    public static function upsert(object $entity, array $conflict, Writer $db, ClockInterface $clock = new SystemClock()): void
    {
        $map = EntityMap::of($entity::class);
        if ($map->requiredScopes !== []) {
            // On a conflict the existing row is overwritten, and no scope can be checked there.
            throw new LogicException(sprintf('upsert() cannot keep %s within its required scopes; insert or update it instead.', $map->class));
        }
        $values = $map->dehydrate(self::stamp($map, $entity, $clock, inserting: true));
        $conflictColumns = array_map(static fn (string $property): string => $map->field($property)->column, $conflict);
        $update = [];
        foreach ($map->fields as $field) {
            if ($field->id || $field->generated || $field->createdAt || in_array($field->property, $conflict, true)) {
                continue;
            }
            foreach ($field->columns() as $column) {
                $update[] = $column;
            }
        }
        foreach ($map->fields as $field) {
            if ($field->generated && ($values[$field->column] ?? null) === null) {
                unset($values[$field->column]);
            }
        }
        Insert::into($map->table)->values($values)->onConflict($conflictColumns, $update)->execute($db);
    }

    /**
     * A copy of an entity with some properties changed, built through its constructor, so
     * its validation runs again. (PHP's clone-with cannot change readonly properties from
     * outside the class.)
     *
     * @template T of object
     * @param T $entity
     * @param array<string, mixed> $changes property => new value
     * @return T
     */
    public static function change(object $entity, array $changes): object
    {
        $map = EntityMap::of($entity::class);
        foreach (array_keys($changes) as $property) {
            if (!array_any($map->fields, static fn (Field $field): bool => $field->property === $property)) {
                throw new MappingError(sprintf('%s has no property "%s".', $entity::class, $property));
            }
        }

        return self::with($map, $entity, $changes);
    }

    /**
     * Maps an entity in hand, such as the result of insert() or update(), into a projection
     * or response DTO whose constructor parameters take entity properties of the same names
     * (and optionally the entity itself). A generated key or timestamp that is still null
     * fails here when the parameter is not nullable, so the DTO can declare `int $id`.
     *
     *     return new Json(Entities::into(Entities::insert($todo, $writer), TodoView::class));
     *
     * #[Computed] parameters take their values from $computed.
     *
     * @template P of object
     * @param class-string<P> $projection
     * @param array<string, mixed> $computed #[Computed] parameter => value
     * @return P
     * @throws MappingError when the projection does not match the entity or the computed values
     */
    public static function into(object $entity, string $projection, array $computed = []): object
    {
        /** @var P */
        return Projection::plan($projection, EntityMap::of($entity::class), [])->assemble($entity, [], $computed);
    }

    /**
     * Loads one relation for entities already in hand, with one batched query.
     *
     * @param list<object> $entities all of one class
     * @param list<Scope> $scopes scopes the related entity requires, applied as with() does
     * @return list<mixed> the related entity (or null, or a list) for each entity, in order
     * @throws ScopeMissing when the related entity requires a scope that is not passed
     * @throws DatabaseError
     */
    public static function load(array $entities, string $relation, Reader $db, array $scopes = []): array
    {
        $first = $entities[0] ?? null;
        if ($first === null) {
            return [];
        }
        $map = EntityMap::of($first::class);
        $loaded = [];
        Loader::load($map, $entities, [$relation => []], $db, $loaded, EntityQuery::of($map->class)->scoped(...$scopes));

        return array_map(static fn (object $entity): mixed => $loaded[spl_object_id($entity)][$relation] ?? null, $entities);
    }

    /**
     * Checks that classes are valid entities, for a test or at boot.
     *
     * @param list<class-string> $classes
     * @throws MappingError for the first invalid class
     */
    public static function check(array $classes): void
    {
        foreach ($classes as $class) {
            $map = EntityMap::of($class);
            foreach ($map->relations as $relation) {
                EntityMap::of($relation->entity);
            }
        }
    }

    /**
     * @param EntityMap<object> $map
     * @return array<string, mixed> key property => value
     */
    private static function keyValues(EntityMap $map, mixed $id): array
    {
        $ids = $map->ids();
        if (count($ids) === 1) {
            return [$ids[0]->property => is_array($id) ? ($id[$ids[0]->property] ?? null) : $id];
        }
        if (!is_array($id)) {
            throw new LogicException(sprintf('%s has a composite key; pass property => value.', $map->class));
        }
        $values = [];
        foreach ($ids as $field) {
            if (!array_key_exists($field->property, $id)) {
                throw new LogicException(sprintf('The key of %s needs "%s".', $map->class, $field->property));
            }
            $values[$field->property] = $id[$field->property];
        }

        return $values;
    }

    /**
     * @param EntityMap<object> $map
     * @return array<string, mixed>
     */
    private static function idOf(EntityMap $map, object $entity): array
    {
        $id = [];
        foreach ($map->ids() as $field) {
            $value = EntityMap::property($entity, $field->property);
            if ($value === null) {
                throw new LogicException(sprintf('The %s has no %s yet; insert it first.', $map->class, $field->property));
            }
            $id[$field->property] = $value;
        }

        return $id;
    }

    /**
     * @param EntityMap<object> $map
     */
    private static function versionField(EntityMap $map): ?Field
    {
        foreach ($map->fields as $field) {
            if ($field->version) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param EntityMap<T> $map
     * Sets timestamps from the clock and the first version on insert.
     *
     * @template T of object
     * @param T $entity
     * @return T
     */
    private static function stamp(EntityMap $map, object $entity, ClockInterface $clock, bool $inserting): object
    {
        $overrides = [];
        $now = null;
        foreach ($map->fields as $field) {
            if ($field->createdAt && $inserting) {
                $overrides[$field->property] = $now ??= self::now($clock);
            }
            if ($field->updatedAt) {
                $overrides[$field->property] = $now ??= self::now($clock);
            }
            if ($field->version && $inserting) {
                $current = EntityMap::property($entity, $field->property);
                $overrides[$field->property] = is_int($current) && $current > 0 ? $current : 1;
            }
        }

        return $overrides === [] ? $entity : self::with($map, $entity, $overrides);
    }

    private static function now(ClockInterface $clock): DateTimeImmutable
    {
        // Microseconds are kept: every supported column type stores them (design/07-date-and-time.md).
        return $clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param EntityMap<T> $map
     * @template T of object
     * @param T $entity
     * @param array<string, mixed> $row column => database value of the fields to replace
     * @return T
     */
    private static function rebuild(EntityMap $map, object $entity, array $row, Reader $db): object
    {
        $fresh = $map->hydrate([...$map->dehydrate($entity), ...$row], $db->dialect());
        $overrides = [];
        foreach ($map->fields as $field) {
            if ($field->kind !== 'embedded' && array_key_exists($field->column, $row)) {
                $overrides[$field->property] = EntityMap::property($fresh, $field->property);
            }
        }

        return $overrides === [] ? $entity : self::with($map, $entity, $overrides);
    }

    /**
     * @param EntityMap<T> $map
     * A copy of a readonly entity with some properties replaced, built through its constructor.
     *
     * @template T of object
     * @param T $entity
     * @param array<string, mixed> $overrides
     * @return T
     */
    private static function with(EntityMap $map, object $entity, array $overrides): object
    {
        $arguments = [];
        foreach ($map->fields as $field) {
            $arguments[$field->property] = array_key_exists($field->property, $overrides)
                ? $overrides[$field->property]
                : EntityMap::property($entity, $field->property);
        }

        return new ($map->class)(...$arguments);
    }

    /**
     * @param EntityMap<object> $map
     * MySQL has no RETURNING: read the generated key from the connection and the other
     * generated columns by selecting the row again.
     *
     * @param list<Field> $generated
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function generatedAfterInsert(EntityMap $map, array $generated, array $values, Writer $db): array
    {
        $row = [];
        $key = null;
        foreach ($generated as $field) {
            if ($field->id) {
                $id = $db->lastInsertId();
                $row[$field->column] = $id;
                $key = [$field->column, $id];
            }
        }
        $others = array_filter($generated, static fn (Field $field): bool => !$field->id);
        if ($others === []) {
            return $row;
        }
        $select = Select::from($map->table)->columns(...array_map(static fn (Field $field): string => $field->column, array_values($others)));
        if ($key !== null) {
            $select = $select->where($key[0], '=', $key[1]);
        } else {
            foreach ($map->ids() as $field) {
                $select = $select->where($field->column, '=', $values[$field->column] ?? null);
            }
        }

        return [...$row, ...($select->fetchOne($db) ?? [])];
    }
}
