<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use Closure;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Reader;
use Shirakaba\Orm\Mapping\EntityMap;
use Shirakaba\Orm\Mapping\Loader;
use Shirakaba\Orm\Mapping\Projection;
use Shirakaba\Query\Conditions;
use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;

/**
 * An immutable query for one entity class, named by properties rather than columns
 * (design/08-database.md). Results are entities, or projections (needed when relations are requested):
 *
 *     Entities::query(Task::class)->where('done', '=', false)->orderBy('createdAt')->fetch($reader);
 *     Entities::query(Post::class)->with('author', 'comments')->into(PostView::class, $reader);
 *
 * @template T of object
 */
final readonly class EntityQuery
{
    /**
     * @param EntityMap<T> $map
     * @param array<string, array<string, mixed>> $with requested relation tree
     * @param list<Scope> $scopes scopes passed with scoped()
     * @param list<class-string<Scope>> $without scope classes switched off
     * @param array<string, Closure(EntityQuery<object>): EntityQuery<object>> $constraints relation path => constraint
     * @param list<string> $counts relations counted with withCount()
     */
    private function __construct(
        private EntityMap $map,
        private Select $select,
        private array $with = [],
        private array $scopes = [],
        private array $without = [],
        private bool $scopesApplied = false,
        private array $constraints = [],
        private array $counts = [],
    ) {}

    /**
     * @template C of object
     * @param class-string<C> $class
     * @return self<C>
     * @throws MappingError when $class is not a valid entity
     */
    public static function of(string $class): self
    {
        $map = EntityMap::of($class);

        return new self(
            $map,
            Select::from($map->table)->columns(...array_map(
                static fn (string $column): string => $map->table . '.' . $column,
                $map->columns(),
            )),
        );
    }

    /**
     * @param string $property a property name, "embedded.property" for embedded values
     * @param mixed $value a property value (converted like the property), Sql or a Select
     * @return self<T>
     */
    public function where(string $property, string $operator, mixed $value): self
    {
        return $this->filter(static fn (Select $select, self $query): Select => $select->where(
            $query->column($property),
            $operator,
            $query->value($property, $value),
        ));
    }

    /**
     * @param list<mixed>|Select $values
     * @return self<T>
     */
    public function whereIn(string $property, array|Select $values): self
    {
        return $this->filter(static fn (Select $select, self $query): Select => $select->whereIn(
            $query->column($property),
            is_array($values) ? array_map(static fn (mixed $value): mixed => $query->value($property, $value), $values) : $values,
        ));
    }

    /**
     * Rows whose property is none of the values, or not in a subquery's results.
     *
     * @param list<mixed>|Select $values
     * @return self<T>
     */
    public function whereNotIn(string $property, array|Select $values): self
    {
        return $this->filter(static fn (Select $select, self $query): Select => $select->whereGroup(
            static fn (\Shirakaba\Query\Conditions $c): \Shirakaba\Query\Conditions => $c->whereNotIn(
                $query->column($property),
                is_array($values) ? array_map(static fn (mixed $value): mixed => $query->value($property, $value), $values) : $values,
            ),
        ));
    }

    /**
     * @return self<T>
     */
    public function whereNull(string $property): self
    {
        return $this->filter(static fn (Select $select, self $query): Select => $select->whereNull($query->column($property)));
    }

    /**
     * @return self<T>
     */
    public function whereNotNull(string $property): self
    {
        return $this->filter(static fn (Select $select, self $query): Select => $select->whereNotNull($query->column($property)));
    }

    /**
     * @return self<T>
     */
    public function whereBetween(string $property, mixed $low, mixed $high): self
    {
        return $this->filter(static fn (Select $select, self $query): Select => $select->whereBetween(
            $query->column($property),
            $query->value($property, $low),
            $query->value($property, $high),
        ));
    }

    /**
     * A group of conditions on properties, combined with AND and OR, joined to the query with
     * AND. Names and values are mapped like where():
     *
     *     ->whereGroup(fn (PropertyConditions $c) => $c->where('userId', '=', $me)->orWhereIn('userId', $followed))
     *
     * @param Closure(PropertyConditions<T>): PropertyConditions<T> $group
     * @return self<T>
     */
    public function whereGroup(Closure $group): self
    {
        return $this->filter(static fn (Select $select, self $query): Select => $select->whereGroup(
            static fn (\Shirakaba\Query\Conditions $conditions): \Shirakaba\Query\Conditions => $group(new PropertyConditions($query, $conditions))->conditions,
        ));
    }

    /**
     * Conditions on columns, for what the property methods cannot express. Columns are named
     * as in the table: `->whereColumns(fn (Conditions $c) => $c->where('tasks.due_on', '<', $d)->orWhereNull('tasks.due_on'))`.
     *
     * @param Closure(Conditions): Conditions $conditions
     * @return self<T>
     */
    public function whereColumns(Closure $conditions): self
    {
        return $this->filter(static fn (Select $select): Select => $select->whereGroup($conditions));
    }

    /**
     * @return self<T>
     */
    public function whereRaw(Sql $condition): self
    {
        return $this->filter(static fn (Select $select): Select => $select->whereRaw($condition));
    }

    /**
     * Rows that have at least one related row, optionally matching more conditions:
     * `->whereHas('comments', fn (EntityQuery $c) => $c->where('approved', '=', true))`.
     *
     * @param (Closure(EntityQuery<object>): EntityQuery<object>)|null $constraint
     * @return self<T>
     */
    public function whereHas(string $relation, ?Closure $constraint = null): self
    {
        return $this->filter(fn (Select $select): Select => $select->whereExists($this->related($relation, $constraint)));
    }

    /**
     * @param (Closure(EntityQuery<object>): EntityQuery<object>)|null $constraint
     * @return self<T>
     */
    public function whereDoesntHave(string $relation, ?Closure $constraint = null): self
    {
        return $this->filter(fn (Select $select): Select => $select->whereNotExists($this->related($relation, $constraint)));
    }

    /**
     * @param 'asc'|'desc'|'ASC'|'DESC' $direction
     * @return self<T>
     */
    public function orderBy(string $property, string $direction = 'asc'): self
    {
        return $this->filter(static fn (Select $select, self $query): Select => $select->orderBy($query->column($property), $direction));
    }

    /**
     * @param int<0, max> $limit
     * @return self<T>
     */
    public function limit(int $limit): self
    {
        return $this->filter(static fn (Select $select): Select => $select->limit($limit));
    }

    /**
     * @param int<0, max> $offset
     * @return self<T>
     */
    public function offset(int $offset): self
    {
        return $this->filter(static fn (Select $select): Select => $select->offset($offset));
    }

    /**
     * Keyset pagination by the ORDER BY properties; see Select::after().
     *
     * @param array<string, mixed> $values property => value of the last entity seen
     * @return self<T>
     */
    public function after(array $values): self
    {
        $columns = [];
        foreach ($values as $property => $value) {
            $columns[$this->column($property)] = $this->value($property, $value);
        }

        return $this->filter(static fn (Select $select): Select => $select->after($columns));
    }

    /**
     * @return self<T>
     */
    public function forUpdate(): self
    {
        return $this->filter(static fn (Select $select): Select => $select->forUpdate());
    }

    /**
     * Requests relations, with dotted paths for nested ones ("comments.author"); results
     * must then be read with into() and a projection class.
     *
     * @return self<T>
     * @throws MappingError when a relation does not exist
     */
    public function with(string ...$relations): self
    {
        $tree = $this->with;
        foreach ($relations as $path) {
            $node = &$tree;
            $map = $this->map;
            foreach (explode('.', $path) as $segment) {
                // Fails early on a misspelt relation.
                $map = EntityMap::of($map->relation($segment)->entity);
                if (!isset($node[$segment]) || !is_array($node[$segment])) {
                    $node[$segment] = [];
                }
                $node = &$node[$segment];
            }
            unset($node);
        }
        /** @var array<string, array<string, mixed>> $tree */

        return clone($this, ['with' => $tree]);
    }

    /**
     * Requests a relation like with(), filtering or ordering the related rows:
     * `->withWhere('comments', fn (EntityQuery $c) => $c->where('approved', '=', true)->orderBy('id', 'desc'))`.
     *
     * @param Closure(EntityQuery<object>): EntityQuery<object> $constraint
     * @return self<T>
     * @throws MappingError when a relation does not exist
     */
    public function withWhere(string $path, Closure $constraint): self
    {
        $query = $this->with($path);

        return clone($query, ['constraints' => [...$query->constraints, $path => $constraint]]);
    }

    /**
     * Counts a has-many or many-to-many relation without loading it, with one grouped query.
     * The projection receives it in an int parameter named "<relation>Count".
     *
     * @return self<T>
     * @throws MappingError when the relation does not exist or is not a list
     */
    public function withCount(string ...$relations): self
    {
        foreach ($relations as $relation) {
            if (!$this->map->relation($relation)->isList()) {
                throw new MappingError(sprintf('withCount() counts list relations; "%s" is not one.', $relation));
            }
        }

        return clone($this, ['counts' => array_values(array_unique([...$this->counts, ...$relations]))]);
    }

    /**
     * @return (Closure(EntityQuery<object>): EntityQuery<object>)|null
     * @internal
     */
    public function constraintFor(string $path): ?Closure
    {
        return $this->constraints[$path] ?? null;
    }

    /**
     * Applies scopes: required ones the entity declares with #[RequiredScope], or any other
     * filter written as a Scope.
     *
     * @return self<T>
     */
    public function scoped(Scope ...$scopes): self
    {
        return clone($this, ['scopes' => [...$this->scopes, ...array_values($scopes)]]);
    }

    /**
     * Switches off scopes by class, such as NotDeleted::class to include deleted rows.
     *
     * @param class-string<Scope> ...$scopes
     * @return self<T>
     */
    public function withoutScope(string ...$scopes): self
    {
        return clone($this, ['without' => [...$this->without, ...array_values($scopes)]]);
    }

    /**
     * @return class-string<T>
     */
    public function entityClass(): string
    {
        return $this->map->class;
    }

    /**
     * @return list<T>
     * @throws DatabaseError
     */
    public function fetch(Reader $db): array
    {
        if ($this->with !== [] || $this->counts !== []) {
            throw new MappingError('Relations were requested with with() or withCount(); read the result with into() and a projection class.');
        }

        return $this->entities($db);
    }

    /**
     * @return T|null
     * @throws DatabaseError
     */
    public function first(Reader $db): ?object
    {
        return $this->limit(1)->fetch($db)[0] ?? null;
    }

    /**
     * Maps each entity and its requested relations into $projection (design/08-database.md). Every
     * requested relation needs a parameter of the same name. Every other parameter takes
     * the entity itself (by type) or the entity property of the same name, so a response
     * DTO with the entity's fields needs no relations and no mapping code.
     *
     * #[From('name')] takes a property, relation or count of another name. #[Computed]
     * parameters take values from $computed, which is called once with the loaded entities,
     * so one batched query can serve the page, and returns the values for each by its index
     *:
     *
     *     ->into(NoteView::class, $db, static fn (array $notes): array => array_map(
     *         static fn (Note $note): array => ['reactions' => $counts[$note->id] ?? []],
     *         $notes,
     *     ))
     *
     * A second parameter receives the related entities that land in nested projections with
     * #[Computed] parameters; return their values after the query's, as if the two
     * lists were one: `fn (array $notes, array $related) => array_map($values, [...$notes,
     * ...$related])`. Each nested projection takes the values of its own #[Computed]
     * parameters and ignores the others; a name no projection declares is an error.
     *
     * @template P of object
     * @param class-string<P> $projection
     * @param (\Closure(list<T>, list<object>): array<int, array<string, mixed>>)|null $computed
     * @return list<P>
     * @throws DatabaseError
     * @throws MappingError when the projection does not match the requested relations or the computed values
     */
    public function into(string $projection, Reader $db, ?\Closure $computed = null): array
    {
        $plan = Projection::plan($projection, $this->map, $this->with, $this->counts);
        $entities = $this->entities($db);
        $loaded = [];
        Loader::load($this->map, $entities, $this->with, $db, $loaded, $this);
        Loader::count($this->map, $entities, $this->counts, $db, $loaded, $this);

        $nestedValues = [];
        $values = [];
        if ($computed !== null && $entities !== []) {
            $nested = [];
            foreach ($entities as $entity) {
                $plan->nestedComputed($entity, $loaded, $nested);
            }
            foreach ($entities as $entity) {
                unset($nested[spl_object_id($entity)]);
            }
            $related = array_values($nested);
            $all = [...$entities, ...$related];
            $values = $computed($entities, $related);
            $names = $plan->computedNames();
            foreach ($values as $index => $entityValues) {
                foreach (array_keys($entityValues) as $name) {
                    if (!isset($names[$name])) {
                        throw new MappingError(sprintf('Projection %s and its nested projections have no #[Computed] parameter $%s.', $projection, $name));
                    }
                }
                $entity = $all[$index] ?? null;
                if ($entity !== null) {
                    $nestedValues[spl_object_id($entity)] = $entityValues;
                }
            }
        }

        $result = [];
        foreach ($entities as $index => $entity) {
            $result[] = $plan->assemble($entity, $loaded, $values[$index] ?? [], $nestedValues, false);
        }

        /** @var list<P> */
        return $result;
    }

    /**
     * @throws DatabaseError
     */
    public function count(Reader $db): int
    {
        return $this->effective()->count($db);
    }

    /**
     * @throws DatabaseError
     */
    public function exists(Reader $db): bool
    {
        return $this->effective()->exists($db);
    }

    /**
     * Updates every row the query matches, in one statement, maintaining the
     * columns the ORM manages: #[Version] is incremented and #[UpdatedAt] set from the clock.
     * Scopes apply, so a tenant's bulk update stays in the tenant.
     *
     * @param array<string, mixed> $changes property => value
     * @return int affected rows (on MySQL, rows actually changed)
     * @throws DatabaseError
     */
    public function update(array $changes, \Shirakaba\Database\Writer $db, \Psr\Clock\ClockInterface $clock = new \Shirakaba\Time\SystemClock()): int
    {
        if ($changes === []) {
            return 0;
        }
        $assignments = [];
        foreach ($changes as $property => $value) {
            $field = $this->map->field($property);
            if ($field->id || $field->generated) {
                throw new \LogicException(sprintf('A bulk update cannot change %s::$%s.', $this->map->class, $property));
            }
            $assignments[$field->column] = $value instanceof Sql ? $value : $this->map->databaseValue($field, $value);
        }

        return $this->updateColumns($assignments, $db, $clock);
    }

    /**
     * Updates columns of the matched rows, adding the managed ones: #[Version] + 1 and
     * #[UpdatedAt] unless given.
     *
     * @param array<string, mixed> $assignments column => database value or Sql
     * @internal
     */
    public function updateColumns(array $assignments, \Shirakaba\Database\Writer $db, \Psr\Clock\ClockInterface $clock): int
    {
        foreach ($this->map->fields as $field) {
            if ($field->version) {
                $assignments[$field->column] = Sql::raw(sprintf('%s + 1', $db->dialect()->quoteIdentifier($field->column)));
            }
            if ($field->updatedAt && !array_key_exists($field->column, $assignments)) {
                $assignments[$field->column] = $clock->now();
            }
        }

        return \Shirakaba\Query\Update::table($this->map->table)
            ->set($assignments)
            ->whereIn($this->idColumn(), $this->targets())
            ->execute($db);
    }

    /**
     * Deletes every row the query matches, in one statement; #[SoftDelete] entities get their
     * deletion time set instead. Scopes apply.
     *
     * @return int affected rows
     * @throws DatabaseError
     */
    public function delete(\Shirakaba\Database\Writer $db, \Psr\Clock\ClockInterface $clock = new \Shirakaba\Time\SystemClock()): int
    {
        if ($this->map->softDelete !== null) {
            return $this->update([$this->map->softDelete => $clock->now()], $db, $clock);
        }

        return $this->forceDelete($db);
    }

    /**
     * Deletes every row the query matches, even of #[SoftDelete] entities.
     *
     * @return int affected rows
     * @throws DatabaseError
     */
    public function forceDelete(\Shirakaba\Database\Writer $db): int
    {
        return \Shirakaba\Query\Delete::from($this->map->table)->whereIn($this->idColumn(), $this->targets())->execute($db);
    }

    private function idColumn(): string
    {
        $ids = $this->map->ids();
        if (count($ids) !== 1) {
            throw new MappingError(sprintf('Bulk updates and deletes of %s need exactly one #[Id].', $this->map->class));
        }

        return $ids[0]->column;
    }

    /**
     * The keys of the matched rows, as a subquery. The extra derived table lets MySQL read
     * the table it updates.
     */
    private function targets(): Select
    {
        $id = $this->idColumn();

        return Select::from($this->effective()->reselect($this->map->table . '.' . $id), 'shirakaba_targets')->columns($id);
    }

    /**
     * The SELECT with scopes applied, for inspection or as a subquery.
     *
     * @throws ScopeMissing when a #[RequiredScope] was neither passed nor switched off
     */
    public function select(): Select
    {
        return $this->effective();
    }

    /**
     * @internal
     */
    public function column(string $property): string
    {
        return $this->map->table . '.' . $this->map->field($property)->column;
    }

    /**
     * @internal
     */
    public function value(string $property, mixed $value): mixed
    {
        return $value instanceof Sql || $value instanceof Select ? $value : $this->map->databaseValue($this->map->field($property), $value);
    }

    /**
     * @param Closure(Select, self<T>): Select $change
     * @return self<T>
     */
    private function filter(Closure $change): self
    {
        return clone($this, ['select' => $change($this->select, $this)]);
    }

    /**
     * The SELECT with every scope applied.
     *
     * @throws ScopeMissing when a #[RequiredScope] was neither passed nor switched off
     */
    private function effective(): Select
    {
        if ($this->scopesApplied) {
            return $this->select;
        }
        $query = clone($this, ['scopes' => [], 'without' => [], 'scopesApplied' => true]);
        $applied = [];
        foreach ($this->scopes as $scope) {
            if (!in_array($scope::class, $this->without, true)) {
                $query = $scope->apply($query);
                $applied[$scope::class] = true;
            }
        }
        foreach ($this->map->defaultScopes as $class) {
            if (!isset($applied[$class]) && !in_array($class, $this->without, true)) {
                $query = new $class()->apply($query);
            }
        }
        foreach ($this->map->requiredScopes as $class) {
            if (!isset($applied[$class]) && !in_array($class, $this->without, true)) {
                throw ScopeMissing::of($this->map->class, $class);
            }
        }

        return $query->select;
    }

    /**
     * The scopes to pass on to a related entity: those it declares.
     *
     * @param EntityMap<object> $related
     * @return array{list<Scope>, list<class-string<Scope>>}
     * @internal
     */
    public function scopesFor(EntityMap $related): array
    {
        return [
            array_values(array_filter($this->scopes, static fn (Scope $scope): bool => $related->declaresScope($scope::class))),
            $this->without,
        ];
    }

    /**
     * @return list<T>
     */
    private function entities(Reader $db): array
    {
        $dialect = $db->dialect();

        return array_map(fn (array $row): object => $this->map->hydrate($row, $dialect), $this->effective()->fetchAll($db));
    }

    /**
     * @param (Closure(EntityQuery<object>): EntityQuery<object>)|null $constraint
     */
    private function related(string $name, ?Closure $constraint): Select
    {
        $relation = $this->map->relation($name);
        $relatedMap = EntityMap::of($relation->entity);
        [$scopes, $without] = $this->scopesFor($relatedMap);
        $related = self::of($relation->entity)->scoped(...$scopes)->withoutScope(...$without);
        if ($constraint !== null) {
            $related = $constraint($related);
        }
        $ownerId = $this->map->ids()[0] ?? throw new MappingError(sprintf('%s has no #[Id].', $this->map->class));
        $relatedIds = $relatedMap->ids();

        $select = $related->select()->columns(Sql::raw('1'));
        return match ($relation->kind) {
            'belongsTo' => $select->whereColumn(
                $relatedMap->table . '.' . ($relatedIds[0] ?? $ownerId)->column,
                '=',
                $this->column($relation->foreignKey),
            ),
            'hasOne', 'hasMany' => $select->whereColumn(
                $relatedMap->table . '.' . $relatedMap->field($relation->foreignKey)->column,
                '=',
                $this->column($ownerId->property),
            ),
            'manyToMany' => $select
                ->join(
                    $relation->through ?? '',
                    ($relation->through ?? '') . '.' . $relation->foreignKey,
                    '=',
                    $relatedMap->table . '.' . ($relatedIds[0] ?? $ownerId)->column,
                )
                ->whereColumn(($relation->through ?? '') . '.' . ($relation->localKey ?? ''), '=', $this->column($ownerId->property)),
        };
    }
}
