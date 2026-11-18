<?php

declare(strict_types=1);

namespace Shirakaba\Query;

use Closure;

/**
 * WHERE methods shared by Select, Update and Delete; see Conditions.
 */
trait Filtered
{
    abstract protected function withWhere(Conditions $where): static;

    abstract protected function currentWhere(): Conditions;

    public function where(string|Sql $column, string $operator, mixed $value): static
    {
        return $this->withWhere($this->currentWhere()->where($column, $operator, $value));
    }

    public function orWhere(string|Sql $column, string $operator, mixed $value): static
    {
        return $this->withWhere($this->currentWhere()->orWhere($column, $operator, $value));
    }

    public function whereColumn(string $left, string $operator, string $right): static
    {
        return $this->withWhere($this->currentWhere()->whereColumn($left, $operator, $right));
    }

    /**
     * @param list<mixed>|Select $values
     */
    public function whereIn(string|Sql $column, array|Select $values): static
    {
        return $this->withWhere($this->currentWhere()->whereIn($column, $values));
    }

    /**
     * @param list<mixed>|Select $values
     */
    public function whereNotIn(string|Sql $column, array|Select $values): static
    {
        return $this->withWhere($this->currentWhere()->whereNotIn($column, $values));
    }

    public function whereNull(string|Sql $column): static
    {
        return $this->withWhere($this->currentWhere()->whereNull($column));
    }

    public function whereNotNull(string|Sql $column): static
    {
        return $this->withWhere($this->currentWhere()->whereNotNull($column));
    }

    public function whereBetween(string|Sql $column, mixed $low, mixed $high): static
    {
        return $this->withWhere($this->currentWhere()->whereBetween($column, $low, $high));
    }

    public function whereExists(Select $query): static
    {
        return $this->withWhere($this->currentWhere()->whereExists($query));
    }

    public function whereNotExists(Select $query): static
    {
        return $this->withWhere($this->currentWhere()->whereNotExists($query));
    }

    public function whereRaw(Sql $condition): static
    {
        return $this->withWhere($this->currentWhere()->whereRaw($condition));
    }

    /**
     * @param Closure(Conditions): Conditions $group
     */
    public function whereGroup(Closure $group): static
    {
        return $this->withWhere($this->currentWhere()->whereGroup($group));
    }

    /**
     * @param Closure(Conditions): Conditions $group
     */
    public function orWhereGroup(Closure $group): static
    {
        return $this->withWhere($this->currentWhere()->orWhereGroup($group));
    }
}
