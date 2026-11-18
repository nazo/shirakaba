<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

use Closure;
use Shirakaba\Database\Dialect\Dialect;

/**
 * Collects the statements of a migration for one dialect. Nothing runs until the migrator
 * executes them, so a dry run shows exactly what would happen.
 */
final class Schema
{
    /** @var list<string> */
    private array $statements = [];

    /** @var list<Operation> */
    private array $operations = [];

    public function __construct(
        public readonly Dialect $dialect,
    ) {}

    /**
     * @param Closure(Blueprint): void $define
     */
    public function create(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);
        $this->operations[] = new Operation('createTable', $table);
        $this->add(...$blueprint->createStatements($this->dialect));
    }

    /**
     * @param Closure(Blueprint): void $define
     */
    public function alter(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);
        foreach ($blueprint->alterOperations() as $operation) {
            $this->operations[] = $operation;
        }
        $this->add(...$blueprint->alterStatements($this->dialect));
    }

    public function drop(string $table): void
    {
        $this->operations[] = new Operation('dropTable', $table);
        $this->add('DROP TABLE ' . $this->dialect->quoteIdentifier($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->operations[] = new Operation('dropTable', $table);
        $this->add('DROP TABLE IF EXISTS ' . $this->dialect->quoteIdentifier($table));
    }

    public function rename(string $from, string $to): void
    {
        $this->operations[] = new Operation('renameTable', $from, $from . ' -> ' . $to);
        $this->add($this->dialect->name() === 'mysql'
            ? sprintf('RENAME TABLE %s TO %s', $this->dialect->quoteIdentifier($from), $this->dialect->quoteIdentifier($to))
            : sprintf('ALTER TABLE %s RENAME TO %s', $this->dialect->quoteIdentifier($from), $this->dialect->quoteIdentifier($to)));
    }

    /**
     * Installs a PostgreSQL extension if it is missing, such as pg_trgm for trigram indexes
     *.
     *
     * @throws \LogicException on databases without extensions
     */
    public function extension(string $name): void
    {
        if ($this->dialect->name() !== 'pgsql') {
            throw new \LogicException(sprintf('Extensions such as "%s" are PostgreSQL only.', $name));
        }
        $this->operations[] = new Operation('createExtension', '', $name);
        $this->add('CREATE EXTENSION IF NOT EXISTS ' . $this->dialect->quoteIdentifier($name));
    }

    /**
     * Any other statement, for example data changes or dialect-specific DDL.
     */
    public function sql(string $statement): void
    {
        $this->operations[] = new Operation('sql', '', $statement);
        $this->add($statement);
    }

    /**
     * What the migration changes, for linting.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    private function add(string ...$statements): void
    {
        foreach ($statements as $statement) {
            $this->statements[] = $statement;
        }
    }
}
