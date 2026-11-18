<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

use InvalidArgumentException;

/**
 * A foreign key being defined. It is always written as a table constraint: MySQL ignores
 * REFERENCES on a column definition.
 */
final class ForeignKey
{
    private string $table = '';
    private string $column = 'id';
    private string $onDelete = 'RESTRICT';
    private string $onUpdate = 'RESTRICT';

    public function __construct(
        public readonly string $localColumn,
        public readonly string $name,
    ) {}

    public function references(string $table, string $column = 'id'): self
    {
        $this->table = $table;
        $this->column = $column;

        return $this;
    }

    /**
     * @param 'cascade'|'set null'|'restrict'|'no action' $action
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = self::action($action);

        return $this;
    }

    /**
     * @param 'cascade'|'set null'|'restrict'|'no action' $action
     */
    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::action($action);

        return $this;
    }

    /**
     * @internal
     */
    public function definition(\Shirakaba\Database\Dialect\Dialect $dialect): string
    {
        if ($this->table === '') {
            throw new \LogicException(sprintf('Foreign key on "%s" needs references().', $this->localColumn));
        }

        return sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            $dialect->quoteIdentifier($this->name),
            $dialect->quoteIdentifier($this->localColumn),
            $dialect->quoteIdentifier($this->table),
            $dialect->quoteIdentifier($this->column),
            $this->onDelete,
            $this->onUpdate,
        );
    }

    private static function action(string $action): string
    {
        $action = strtoupper($action);
        if (!in_array($action, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)) {
            throw new InvalidArgumentException(sprintf('Unknown foreign key action "%s".', $action));
        }

        return $action;
    }
}
