<?php

declare(strict_types=1);

namespace Shirakaba\Query;

use Closure;
use Generator;
use InvalidArgumentException;
use LogicException;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Reader;
use Shirakaba\Query\Internal\Output;
use Shirakaba\Query\Internal\RowComparison;

/**
 * An immutable SELECT. Runs with a Reader, so read-only handlers can use it:
 *
 *     $open = Select::from('tasks')
 *         ->columns('id', 'title')
 *         ->where('done', '=', false)
 *         ->orderBy('id')
 *         ->limit(20)
 *         ->fetchInto(TaskSummary::class, $reader);
 *
 * Names are validated identifiers ("column", "table.column", "table.*"); everything else
 * goes through Sql::raw(), and every value is a bound parameter.
 */
final readonly class Select
{
    use Filtered;

    /**
     * @param list<array{string, Select}> $ctes
     * @param list<array{string|Sql, ?string}> $columns
     * @param list<array{'INNER'|'LEFT', string|Select, ?string, Conditions}> $joins
     * @param list<string|Sql> $groupBy
     * @param list<array{string|Sql, 'ASC'|'DESC'}> $orderBy
     * @param list<array{Select, bool}> $unions
     * @param 'UPDATE'|'SHARE'|'UPDATE SKIP LOCKED'|null $lock
     */
    private function __construct(
        private string|Select $from,
        private ?string $alias = null,
        private array $ctes = [],
        private bool $distinct = false,
        private array $columns = [],
        private array $joins = [],
        private Conditions $where = new Conditions(),
        private array $groupBy = [],
        private Conditions $having = new Conditions(),
        private array $orderBy = [],
        private ?int $limit = null,
        private ?int $offset = null,
        private ?string $lock = null,
        private array $unions = [],
    ) {}

    /**
     * @param string|Select $table a table name, or a subquery that then needs an alias
     */
    public static function from(string|Select $table, ?string $as = null): self
    {
        if ($table instanceof Select && $as === null) {
            throw new InvalidArgumentException('A subquery in FROM needs an alias.');
        }

        return new self($table, $as);
    }

    /**
     * A common table expression: `->with('recent', Select::from(...))`, then use "recent" as a table.
     */
    public function with(string $name, Select $query): self
    {
        return clone($this, ['ctes' => [...$this->ctes, [$name, $query]]]);
    }

    public function distinct(): self
    {
        return clone($this, ['distinct' => true]);
    }

    /**
     * Adds columns by name; without any, the query selects "*".
     */
    public function columns(string|Sql ...$columns): self
    {
        $added = array_map(static fn (string|Sql $column): array => [$column, null], array_values($columns));

        return clone($this, ['columns' => [...$this->columns, ...$added]]);
    }

    /**
     * Replaces the selected columns; without arguments the query selects "*" again.
     */
    public function reselect(string|Sql ...$columns): self
    {
        return clone($this, ['columns' => array_map(static fn (string|Sql $column): array => [$column, null], array_values($columns))]);
    }

    /**
     * Adds one column or expression under an alias: `->column(Sql::raw('COUNT(*)'), 'total')`.
     */
    public function column(string|Sql $column, string $as): self
    {
        return clone($this, ['columns' => [...$this->columns, [$column, $as]]]);
    }

    public function join(string|Select $table, string $left, string $operator, string $right, ?string $as = null): self
    {
        return $this->joinWith('INNER', $table, Conditions::none()->whereColumn($left, $operator, $right), $as);
    }

    public function leftJoin(string|Select $table, string $left, string $operator, string $right, ?string $as = null): self
    {
        return $this->joinWith('LEFT', $table, Conditions::none()->whereColumn($left, $operator, $right), $as);
    }

    /**
     * A join with any ON conditions: `->joinOn('tags', fn (Conditions $on) => $on->whereColumn(...)->where(...))`.
     *
     * @param Closure(Conditions): Conditions $on
     * @param 'INNER'|'LEFT' $type
     */
    public function joinOn(string|Select $table, Closure $on, ?string $as = null, string $type = 'INNER'): self
    {
        return $this->joinWith($type, $table, $on(Conditions::none()), $as);
    }

    public function groupBy(string|Sql ...$columns): self
    {
        return clone($this, ['groupBy' => [...$this->groupBy, ...array_values($columns)]]);
    }

    /**
     * @param Closure(Conditions): Conditions $having
     */
    public function having(Closure $having): self
    {
        return clone($this, ['having' => $having($this->having)]);
    }

    /**
     * @param 'asc'|'desc'|'ASC'|'DESC' $direction
     */
    public function orderBy(string|Sql $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new InvalidArgumentException('The order direction must be "asc" or "desc".');
        }

        return clone($this, ['orderBy' => [...$this->orderBy, [$column, $direction]]]);
    }

    /**
     * @param int<0, max> $limit
     */
    public function limit(int $limit): self
    {
        return clone($this, ['limit' => $limit]);
    }

    /**
     * @param int<0, max> $offset
     */
    public function offset(int $offset): self
    {
        return clone($this, ['offset' => $offset]);
    }

    /**
     * Keyset pagination: rows after the given values of the ORDER BY columns, which must all
     * run in one direction. Faster and more stable than OFFSET on large tables:
     *
     *     ->orderBy('created_at')->orderBy('id')->after(['created_at' => $last->createdAt, 'id' => $last->id])
     *
     * @param array<string, mixed> $values ORDER BY column => value of the last row seen
     */
    public function after(array $values): self
    {
        $columns = array_map(static fn (array $order): string|Sql => $order[0], $this->orderBy);
        $directions = array_values(array_unique(array_map(static fn (array $order): string => $order[1], $this->orderBy)));
        if ($columns === [] || $columns !== array_keys($values) || count($directions) !== 1) {
            throw new LogicException('after() needs values for exactly the ORDER BY columns, in order, all ascending or all descending.');
        }

        $bounds = array_values($values);
        if ($bounds === []) {
            throw new LogicException('after() needs at least one value.');
        }

        return $this->withWhere($this->where->withCondition(new RowComparison($columns, $bounds, $directions[0] === 'DESC' ? '<' : '>')));
    }

    /**
     * Locks the selected rows until the transaction ends. SQLite has no row locks and
     * serialises writers instead, so the clause is left out there.
     */
    public function forUpdate(): self
    {
        return clone($this, ['lock' => 'UPDATE']);
    }

    /**
     * Locks the selected rows and skips rows other transactions hold, so concurrent workers
     * each take different rows (MySQL 8, PostgreSQL). Left out on SQLite, which serialises
     * writers.
     */
    public function forUpdateSkipLocked(): self
    {
        return clone($this, ['lock' => 'UPDATE SKIP LOCKED']);
    }

    public function forShare(): self
    {
        return clone($this, ['lock' => 'SHARE']);
    }

    public function union(Select $query, bool $all = false): self
    {
        return clone($this, ['unions' => [...$this->unions, [$query, $all]]]);
    }

    /**
     * The tables the query reads in FROM, JOIN, UNION and CTEs, for cache tags.
     * Tables only named inside WHERE subqueries are not included.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $ctes = array_map(static fn (array $cte): string => $cte[0], $this->ctes);
        $tables = [];
        $add = static function (string|Select $source) use (&$tables): void {
            foreach (is_string($source) ? [$source] : $source->tables() as $table) {
                $tables[] = $table;
            }
        };
        $add($this->from);
        foreach ($this->joins as [, $table]) {
            $add($table);
        }
        foreach ($this->unions as [$query]) {
            $add($query);
        }
        foreach ($this->ctes as [, $query]) {
            $add($query);
        }

        return array_values(array_unique(array_diff($tables, $ctes)));
    }

    public function toSql(Dialect $dialect): Compiled
    {
        $out = new Output($dialect);

        return $out->result($this->compileInto($out));
    }

    /**
     * @internal
     */
    public function compileInto(Output $out): string
    {
        $sql = '';
        if ($this->ctes !== []) {
            $sql .=
                'WITH '
                . implode(', ', array_map(
                    static fn (array $cte): string => $out->identifier($cte[0]) . ' AS (' . $cte[1]->compileInto($out) . ')',
                    $this->ctes,
                ))
                . ' ';
        }
        $sql .= 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '');
        $sql .= $this->columns === []
            ? '*'
            : implode(', ', array_map(
                static fn (array $column): string => self::expression($out, $column[0]) . ($column[1] === null ? '' : ' AS ' . $out->identifier($column[1])),
                $this->columns,
            ));
        $sql .= ' FROM ' . self::table($out, $this->from, $this->alias);
        foreach ($this->joins as [$type, $table, $alias, $on]) {
            $sql .= sprintf(' %s JOIN %s ON %s', $type, self::table($out, $table, $alias), $on->compile($out));
        }
        if (!$this->where->isEmpty()) {
            $sql .= ' WHERE ' . $this->where->compile($out);
        }
        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(static fn (string|Sql $column): string => self::expression($out, $column), $this->groupBy));
        }
        if (!$this->having->isEmpty()) {
            $sql .= ' HAVING ' . $this->having->compile($out);
        }
        foreach ($this->unions as [$query, $all]) {
            $sql .= ($all ? ' UNION ALL ' : ' UNION ') . $query->compileInto($out);
        }
        if ($this->orderBy !== []) {
            $sql .=
                ' ORDER BY '
                . implode(', ', array_map(
                    static fn (array $order): string => self::expression($out, $order[0]) . ' ' . $order[1],
                    $this->orderBy,
                ));
        }
        $limit = $out->dialect->limit($this->limit, $this->offset);
        if ($limit !== '') {
            $sql .= ' ' . $limit;
        }
        if ($this->lock !== null && $out->dialect->supportsLocking()) {
            $sql .= ' FOR ' . $this->lock;
        }

        return $sql;
    }

    /**
     * @return list<array<string, mixed>>
     * @throws DatabaseError
     */
    public function fetchAll(Reader $db): array
    {
        $compiled = $this->toSql($db->dialect());

        return $db->fetchAll($compiled->sql, $compiled->parameters);
    }

    /**
     * @return array<string, mixed>|null
     * @throws DatabaseError
     */
    public function fetchOne(Reader $db): ?array
    {
        $compiled = $this->limit(1)->toSql($db->dialect());

        return $db->fetchOne($compiled->sql, $compiled->parameters);
    }

    /**
     * @throws DatabaseError
     */
    public function fetchValue(Reader $db): mixed
    {
        $compiled = $this->limit(1)->toSql($db->dialect());

        return $db->fetchValue($compiled->sql, $compiled->parameters);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     * @throws DatabaseError
     */
    public function iterate(Reader $db): Generator
    {
        $compiled = $this->toSql($db->dialect());

        return $db->iterate($compiled->sql, $compiled->parameters);
    }

    /**
     * Maps each row into $class through its constructor (see RowMapper).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     * @throws DatabaseError
     */
    public function fetchInto(string $class, Reader $db): array
    {
        $mapper = RowMapper::for($class, $db->dialect());

        return array_map($mapper->map(...), $this->fetchAll($db));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     * @throws DatabaseError
     */
    public function fetchOneInto(string $class, Reader $db): ?object
    {
        $row = $this->fetchOne($db);

        return $row === null ? null : RowMapper::for($class, $db->dialect())->map($row);
    }

    /**
     * The number of rows the query returns, ignoring ORDER BY, LIMIT and OFFSET.
     *
     * @throws DatabaseError
     */
    public function count(Reader $db): int
    {
        $inner = clone($this, ['orderBy' => [], 'limit' => null, 'offset' => null, 'lock' => null]);
        $value = self::from($inner, 'counted')->column(Sql::raw('COUNT(*)'), 'total')->fetchValue($db);

        return is_int($value) || is_string($value) ? (int) $value : 0;
    }

    /**
     * Whether the query returns any row.
     *
     * @throws DatabaseError
     */
    public function exists(Reader $db): bool
    {
        $probe = clone($this, ['columns' => [[Sql::raw('1'), 'present']], 'orderBy' => [], 'lock' => null]);

        return $probe->fetchValue($db) !== null;
    }

    protected function withWhere(Conditions $where): static
    {
        return clone($this, ['where' => $where]);
    }

    protected function currentWhere(): Conditions
    {
        return $this->where;
    }

    /**
     * @param 'INNER'|'LEFT' $type
     */
    private function joinWith(string $type, string|Select $table, Conditions $on, ?string $as): self
    {
        if ($table instanceof Select && $as === null) {
            throw new InvalidArgumentException('A joined subquery needs an alias.');
        }

        return clone($this, ['joins' => [...$this->joins, [$type, $table, $as, $on]]]);
    }

    private static function table(Output $out, string|Select $table, ?string $alias): string
    {
        $sql = $table instanceof Select ? '(' . $table->compileInto($out) . ')' : $out->identifier($table);

        return $alias === null ? $sql : $sql . ' AS ' . $out->identifier($alias);
    }

    private static function expression(Output $out, string|Sql $expression): string
    {
        return $expression instanceof Sql ? $out->raw($expression) : $out->identifier($expression);
    }
}
