<?php

declare(strict_types=1);

namespace Shirakaba\Query;

use Closure;
use Shirakaba\Query\Internal\Comparison;
use Shirakaba\Query\Internal\Condition;
use Shirakaba\Query\Internal\InList;
use Shirakaba\Query\Internal\Output;
use Shirakaba\Query\Internal\Simple;

/**
 * Conditions joined by AND and OR, for WHERE and HAVING. Immutable: every method returns a
 * new instance. Nested groups are built in closures:
 *
 *     ->where('status', '=', 'open')
 *     ->whereGroup(fn (Conditions $c) => $c->where('priority', '>', 3)->orWhereNull('due'))
 */
final readonly class Conditions
{
    /**
     * @param list<array{'AND'|'OR', Condition}> $parts
     * @internal use none() and the where methods
     */
    public function __construct(
        private array $parts = [],
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->parts === [];
    }

    /**
     * @param string $operator =, <>, !=, <, <=, >, >=, LIKE or NOT LIKE
     * @param mixed $value a bindable value, Sql or a Select subquery; never null
     */
    public function where(string|Sql $column, string $operator, mixed $value): self
    {
        return $this->with('AND', new Comparison($column, $operator, $value));
    }

    public function orWhere(string|Sql $column, string $operator, mixed $value): self
    {
        return $this->with('OR', new Comparison($column, $operator, $value));
    }

    /**
     * Compares two columns: `whereColumn('orders.customer_id', '=', 'customers.id')`.
     */
    public function whereColumn(string $left, string $operator, string $right): self
    {
        return $this->with('AND', new Comparison($left, $operator, $right, columnValue: true));
    }

    /**
     * @param list<mixed>|Select $values an empty list matches nothing
     */
    public function whereIn(string|Sql $column, array|Select $values): self
    {
        return $this->with('AND', new InList($column, $values, false));
    }

    /**
     * @param list<mixed>|Select $values an empty list matches everything
     */
    public function whereNotIn(string|Sql $column, array|Select $values): self
    {
        return $this->with('AND', new InList($column, $values, true));
    }

    /**
     * @param list<mixed>|Select $values
     */
    public function orWhereIn(string|Sql $column, array|Select $values): self
    {
        return $this->with('OR', new InList($column, $values, false));
    }

    /**
     * @param list<mixed>|Select $values
     */
    public function orWhereNotIn(string|Sql $column, array|Select $values): self
    {
        return $this->with('OR', new InList($column, $values, true));
    }

    public function whereNull(string|Sql $column): self
    {
        return $this->with('AND', new Simple('null', $column));
    }

    public function whereNotNull(string|Sql $column): self
    {
        return $this->with('AND', new Simple('null', $column, true));
    }

    public function orWhereNull(string|Sql $column): self
    {
        return $this->with('OR', new Simple('null', $column));
    }

    public function whereBetween(string|Sql $column, mixed $low, mixed $high): self
    {
        return $this->with('AND', new Simple('between', $column, range: [$low, $high]));
    }

    public function whereExists(Select $query): self
    {
        return $this->with('AND', new Simple('exists', $query));
    }

    public function whereNotExists(Select $query): self
    {
        return $this->with('AND', new Simple('exists', $query, true));
    }

    public function whereRaw(Sql $condition): self
    {
        return $this->with('AND', new Simple('raw', $condition));
    }

    public function orWhereRaw(Sql $condition): self
    {
        return $this->with('OR', new Simple('raw', $condition));
    }

    /**
     * @param Closure(Conditions): Conditions $group
     */
    public function whereGroup(Closure $group): self
    {
        return $this->group('AND', $group);
    }

    /**
     * @param Closure(Conditions): Conditions $group
     */
    public function orWhereGroup(Closure $group): self
    {
        return $this->group('OR', $group);
    }

    /**
     * @internal
     */
    public function withCondition(Condition $condition): self
    {
        return $this->with('AND', $condition);
    }

    /**
     * @internal
     */
    public function compile(Output $out): string
    {
        $sql = '';
        foreach ($this->parts as $index => [$boolean, $condition]) {
            $sql .= ($index === 0 ? '' : ' ' . $boolean . ' ') . $condition->compile($out);
        }

        return $sql;
    }

    /**
     * @param 'AND'|'OR' $boolean
     */
    private function with(string $boolean, Condition $condition): self
    {
        return new self([...$this->parts, [$boolean, $condition]]);
    }

    /**
     * @param 'AND'|'OR' $boolean
     * @param Closure(Conditions): Conditions $group
     */
    private function group(string $boolean, Closure $group): self
    {
        $inner = $group(new self());
        if ($inner->isEmpty()) {
            return $this;
        }

        return $this->with($boolean, new class ($inner) implements Condition {
            public function __construct(private Conditions $inner) {}

            public function compile(Output $out): string
            {
                return '(' . $this->inner->compile($out) . ')';
            }
        });
    }
}
