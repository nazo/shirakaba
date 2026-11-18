<?php

declare(strict_types=1);

namespace Shirakaba\Query;

use LogicException;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Writer;
use Shirakaba\Query\Internal\Output;

/**
 * An immutable DELETE, run with a Writer. Like Update, it needs a WHERE clause or an
 * explicit everyRow().
 */
final readonly class Delete
{
    use Filtered;

    private function __construct(
        private string $table,
        private Conditions $where = new Conditions(),
        private bool $everyRow = false,
    ) {}

    public static function from(string $table): self
    {
        return new self($table);
    }

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
        if ($this->where->isEmpty() && !$this->everyRow) {
            throw new LogicException(sprintf('The delete from "%s" has no WHERE clause; call everyRow() if that is intended.', $this->table));
        }
        $out = new Output($dialect);
        $sql = 'DELETE FROM ' . $out->identifier($this->table);
        if (!$this->where->isEmpty()) {
            $sql .= ' WHERE ' . $this->where->compile($out);
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

    protected function withWhere(Conditions $where): static
    {
        return clone($this, ['where' => $where]);
    }

    protected function currentWhere(): Conditions
    {
        return $this->where;
    }
}
