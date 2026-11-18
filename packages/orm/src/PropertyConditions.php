<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use Closure;
use Shirakaba\Query\Conditions;
use Shirakaba\Query\Select;

/**
 * A group of conditions on entity properties, combined with AND and OR, for
 * EntityQuery::whereGroup(). Names and values are mapped like where(): property
 * names become columns, and enums, instants and value objects are converted.
 *
 *     ->whereGroup(fn (PropertyConditions $c) => $c->where('userId', '=', $me)->orWhereIn('userId', $followed))
 *
 * @template T of object
 */
final readonly class PropertyConditions
{
    /**
     * @param EntityQuery<T> $query
     * @internal created by EntityQuery::whereGroup()
     */
    public function __construct(
        private EntityQuery $query,
        public Conditions $conditions = new Conditions(),
    ) {}

    /**
     * @param string $operator =, <>, !=, <, <=, >, >=, LIKE or NOT LIKE
     * @return self<T>
     */
    public function where(string $property, string $operator, mixed $value): self
    {
        return $this->with($this->conditions->where($this->query->column($property), $operator, $this->query->value($property, $value)));
    }

    /**
     * @return self<T>
     */
    public function orWhere(string $property, string $operator, mixed $value): self
    {
        return $this->with($this->conditions->orWhere($this->query->column($property), $operator, $this->query->value($property, $value)));
    }

    /**
     * @param list<mixed>|Select $values
     * @return self<T>
     */
    public function whereIn(string $property, array|Select $values): self
    {
        return $this->with($this->conditions->whereIn($this->query->column($property), $this->values($property, $values)));
    }

    /**
     * @param list<mixed>|Select $values
     * @return self<T>
     */
    public function orWhereIn(string $property, array|Select $values): self
    {
        return $this->with($this->conditions->orWhereIn($this->query->column($property), $this->values($property, $values)));
    }

    /**
     * @param list<mixed>|Select $values
     * @return self<T>
     */
    public function whereNotIn(string $property, array|Select $values): self
    {
        return $this->with($this->conditions->whereNotIn($this->query->column($property), $this->values($property, $values)));
    }

    /**
     * @param list<mixed>|Select $values
     * @return self<T>
     */
    public function orWhereNotIn(string $property, array|Select $values): self
    {
        return $this->with($this->conditions->orWhereNotIn($this->query->column($property), $this->values($property, $values)));
    }

    /**
     * @return self<T>
     */
    public function whereNull(string $property): self
    {
        return $this->with($this->conditions->whereNull($this->query->column($property)));
    }

    /**
     * @return self<T>
     */
    public function orWhereNull(string $property): self
    {
        return $this->with($this->conditions->orWhereNull($this->query->column($property)));
    }

    /**
     * @return self<T>
     */
    public function whereNotNull(string $property): self
    {
        return $this->with($this->conditions->whereNotNull($this->query->column($property)));
    }

    /**
     * A nested group joined with AND: `$c->where(...)->whereGroup(fn ($g) => $g->where(...)->orWhere(...))`.
     *
     * @param Closure(self<T>): self<T> $group
     * @return self<T>
     */
    public function whereGroup(Closure $group): self
    {
        return $this->with($this->conditions->whereGroup(fn (Conditions $inner): Conditions => $group(new self($this->query, $inner))->conditions));
    }

    /**
     * A nested group joined with OR.
     *
     * @param Closure(self<T>): self<T> $group
     * @return self<T>
     */
    public function orWhereGroup(Closure $group): self
    {
        return $this->with($this->conditions->orWhereGroup(fn (Conditions $inner): Conditions => $group(new self($this->query, $inner))->conditions));
    }

    /**
     * @param list<mixed>|Select $values
     * @return list<mixed>|Select
     */
    private function values(string $property, array|Select $values): array|Select
    {
        return is_array($values) ? array_map(fn (mixed $value): mixed => $this->query->value($property, $value), $values) : $values;
    }

    /**
     * @return self<T>
     */
    private function with(Conditions $conditions): self
    {
        return new self($this->query, $conditions);
    }
}
