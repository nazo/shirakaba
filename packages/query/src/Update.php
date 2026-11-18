<?php

declare(strict_types=1);

namespace Shirakaba\Query;

use LogicException;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Writer;
use Shirakaba\Query\Internal\Output;

/**
 * An immutable UPDATE, run with a Writer. Without a WHERE clause it refuses to run unless
 * everyRow() says so, because an unfiltered update is usually a bug:
 *
 *     Update::table('tasks')->set(['done' => true])->where('id', '=', $id)->execute($writer);
 *     Update::table('counters')->set(['hits' => Sql::raw('hits + 1')])->where(...)->execute($writer);
 */
final readonly class Update
{
    use Filtered;

    /**
     * @param array<string, mixed> $values
     */
    private function __construct(
        private string $table,
        private array $values = [],
        private Conditions $where = new Conditions(),
        private bool $everyRow = false,
    ) {}

    public static function table(string $table): self
    {
        return new self($table);
    }

    /**
     * @param array<string, mixed> $values column => value or Sql expression
     */
    public function set(array $values): self
    {
        return clone($this, ['values' => [...$this->values, ...$values]]);
    }

    /**
     * Allows the update to run without a WHERE clause.
     */
    public function everyRow(): self
    {
        return clone($this, ['everyRow' => true]);
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
        if ($this->values === []) {
            throw new LogicException(sprintf('The update of "%s" sets no columns.', $this->table));
        }
        if ($this->where->isEmpty() && !$this->everyRow) {
            throw new LogicException(sprintf('The update of "%s" has no WHERE clause; call everyRow() if that is intended.', $this->table));
        }
        $out = new Output($dialect);
        $assignments = [];
        foreach ($this->values as $column => $value) {
            $assignments[] = $out->identifier($column) . ' = ' . $out->value($value);
        }
        $sql = sprintf('UPDATE %s SET %s', $out->identifier($this->table), implode(', ', $assignments));
        if (!$this->where->isEmpty()) {
            $sql .= ' WHERE ' . $this->where->compile($out);
        }

        return $out->result($sql);
    }

    /**
     * @return int affected rows (on MySQL, rows actually changed)
     * @throws DatabaseError
     */
    public function execute(Writer $db): int
    {
        $compiled = $this->toSql($db->dialect());

        return $db->execute($compiled->sql, $compiled->parameters);
    }

    protected function withWhere(Conditions $where): static
    {
        return clone($this, ['where' => $where]);
    }

    protected function currentWhere(): Conditions
    {
        return $this->where;
    }
}
