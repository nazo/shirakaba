<?php

declare(strict_types=1);

namespace Shirakaba\Query;

use InvalidArgumentException;
use LogicException;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Writer;
use Shirakaba\Query\Internal\Output;

/**
 * An immutable INSERT, run with a Writer:
 *
 *     Insert::into('tasks')->values(['title' => 'Write docs', 'done' => false])->execute($writer);
 *     $id = Insert::into('tasks')->values([...])->executeAndGetId($writer, 'id');
 *     Insert::into('users')->values([...])->onConflict(['email'], update: ['name'])->execute($writer);
 */
final readonly class Insert
{
    /**
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     * @param list<string>|null $conflict
     * @param array<int|string, string|Sql> $update
     * @param list<string> $returning
     */
    private function __construct(
        private string $table,
        private array $columns = [],
        private array $rows = [],
        private ?Select $select = null,
        private ?array $conflict = null,
        private array $update = [],
        private array $returning = [],
    ) {}

    public static function into(string $table): self
    {
        return new self($table);
    }

    /**
     * Adds one row; every row must name the same columns.
     *
     * @param array<string, mixed> $row column => value
     */
    public function values(array $row): self
    {
        if ($row === []) {
            throw new InvalidArgumentException('An inserted row needs at least one column.');
        }
        $columns = array_keys($row);
        if ($this->columns !== [] && $columns !== $this->columns) {
            throw new InvalidArgumentException('Every inserted row must have the same columns in the same order.');
        }

        return clone($this, ['columns' => $columns, 'rows' => [...$this->rows, array_values($row)]]);
    }

    /**
     * Adds several rows at once.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function rows(array $rows): self
    {
        $insert = $this;
        foreach ($rows as $row) {
            $insert = $insert->values($row);
        }

        return $insert;
    }

    /**
     * INSERT INTO ... SELECT.
     *
     * @param list<string> $columns
     */
    public function fromSelect(array $columns, Select $select): self
    {
        return clone($this, ['columns' => $columns, 'rows' => [], 'select' => $select]);
    }

    /**
     * Turns the insert into an upsert: on a conflict on $columns (a unique key), overwrite
     * $update with the inserted values, or do nothing when $update is empty. An entry
     * `'column' => Sql` sets the column to an expression instead, in which
     * `<table>.<column>` is the stored row and `excluded.<column>` the inserted one, on
     * every database:
     *
     *     Insert::into('reaction_counts')->values(['note_id' => $id, 'reaction' => $r, 'count' => 1])
     *         ->onConflict(['note_id', 'reaction'], ['count' => Sql::raw('reaction_counts.count + excluded.count')]);
     *
     * MySQL decides the conflicting key itself and ignores $columns; "do nothing" is
     * emulated there by assigning a conflict column to itself.
     *
     * @param list<string> $columns
     * @param array<int|string, string|Sql> $update columns to copy from the inserted row, or column => expression
     */
    public function onConflict(array $columns, array $update = []): self
    {
        return clone($this, ['conflict' => $columns, 'update' => $update]);
    }

    /**
     * Columns to return from the inserted rows (PostgreSQL and SQLite; not MySQL).
     */
    public function returning(string ...$columns): self
    {
        return clone($this, ['returning' => array_values($columns)]);
    }

    /**
     * The table the statement writes.
     */
    public function tableName(): string
    {
        return $this->table;
    }

    public function toSql(Dialect $dialect): Compiled
    {
        if ($this->columns === []) {
            throw new LogicException(sprintf('The insert into "%s" has no values.', $this->table));
        }
        $out = new Output($dialect);
        $sql = sprintf(
            'INSERT INTO %s (%s) ',
            $out->identifier($this->table),
            implode(', ', array_map($out->identifier(...), $this->columns)),
        );
        $sql .= $this->select !== null
            ? $this->select->compileInto($out)
            : 'VALUES ' . implode(', ', array_map(
                static fn (array $row): string => '(' . implode(', ', array_map($out->value(...), $row)) . ')',
                $this->rows,
            ));
        if ($this->conflict !== null) {
            $assignments = [];
            foreach ($this->update as $key => $value) {
                if ($value instanceof Sql) {
                    $assignments[$out->identifier((string) $key)] = $out->raw($value);
                } else {
                    $column = $out->identifier($value);
                    $assignments[$column] = 'excluded.' . $column;
                }
            }
            $sql .= ' ' . $dialect->upsert(
                $out->identifier($this->table),
                array_map($out->identifier(...), $this->conflict),
                $assignments,
            );
        }
        if ($this->returning !== []) {
            if (!$dialect->supportsReturning()) {
                throw new LogicException(sprintf('%s does not support RETURNING; use executeAndGetId() or a separate SELECT.', $dialect->name()));
            }
            $sql .= ' RETURNING ' . implode(', ', array_map($out->identifier(...), $this->returning));
        }

        return $out->result($sql);
    }

    /**
     * @return int affected rows
     * @throws DatabaseError
     */
    public function execute(Writer $db): int
    {
        $compiled = $this->toSql($db->dialect());

        return $db->execute($compiled->sql, $compiled->parameters);
    }

    /**
     * Runs an insert with returning() and gives back the returned rows.
     *
     * @return list<array<string, mixed>>
     * @throws DatabaseError
     */
    public function executeReturning(Writer $db): array
    {
        if ($this->returning === []) {
            throw new LogicException('executeReturning() needs returning(...) columns.');
        }
        $compiled = $this->toSql($db->dialect());

        return $db->fetchAll($compiled->sql, $compiled->parameters);
    }

    /**
     * Inserts one row and returns its generated key, using RETURNING where supported and
     * the connection's last insert ID on MySQL.
     *
     * @throws DatabaseError
     */
    public function executeAndGetId(Writer $db, string $key = 'id'): int|string
    {
        if (count($this->rows) !== 1) {
            throw new LogicException('executeAndGetId() inserts exactly one row.');
        }
        if ($db->dialect()->supportsReturning()) {
            $rows = $this->returning($key)->executeReturning($db);
            $value = $rows[0][$key] ?? null;
        } else {
            $this->execute($db);
            $value = $db->lastInsertId();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            return preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : $value;
        }

        throw new LogicException(sprintf('The insert into "%s" returned no "%s".', $this->table, $key));
    }
}
