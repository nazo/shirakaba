<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

use InvalidArgumentException;
use LogicException;
use Shirakaba\Database\Dialect\Dialect;

/**
 * An index being defined: on columns or on an expression, with an index method,
 * an operator class and, on PostgreSQL, a statistics target for an expression.
 *
 *     $table->index(['user_id', 'id']);
 *     $table->expressionIndex('notes_search', "lower(coalesce(cw, '') || ' ' || coalesce(text, ''))")
 *         ->using('gin')->operatorClass('gin_trgm_ops')->statistics(1000);
 *
 * What a database cannot express fails when the migration's statements are built, so a
 * migration never silently means something else on another database.
 */
final class Index
{
    /** @var list<string> */
    private const array METHODS = ['btree', 'hash', 'gin', 'gist', 'brin', 'spgist'];

    private ?string $method = null;
    private ?string $operatorClass = null;
    private ?int $statistics = null;

    /**
     * @param list<string> $columns empty for an expression index
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $unique,
        public readonly array $columns,
        public readonly ?string $expression = null,
    ) {}

    /**
     * The index method: btree (the default), hash, gin, gist, brin or spgist. MySQL accepts
     * btree and hash; SQLite only btree.
     *
     * @throws InvalidArgumentException for an unknown method
     */
    public function using(string $method): self
    {
        $method = strtolower($method);
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown index method "%s"; use one of %s.', $method, implode(', ', self::METHODS)));
        }
        $this->method = $method;

        return $this;
    }

    /**
     * A PostgreSQL operator class for every key, such as gin_trgm_ops for trigram search.
     *
     * @throws InvalidArgumentException for a name that is not an identifier
     */
    public function operatorClass(string $class): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/D', $class) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not an operator class name.', $class));
        }
        $this->operatorClass = $class;

        return $this;
    }

    /**
     * The PostgreSQL statistics target of an expression index (default 100, up to 10000). A
     * higher target helps the planner judge rare values, as in text search.
     *
     * @throws InvalidArgumentException outside 1 to 10000
     */
    public function statistics(int $target): self
    {
        if ($target < 1 || $target > 10_000) {
            throw new InvalidArgumentException('A statistics target is between 1 and 10000.');
        }
        $this->statistics = $target;

        return $this;
    }

    /**
     * @internal
     * @return list<string>
     * @throws LogicException when the database cannot express the index
     */
    public function statements(Dialect $dialect, string $table): array
    {
        $database = $dialect->name();
        $method = $this->method;
        if ($method !== null && $method !== 'btree' && !($database === 'pgsql' || ($database === 'mysql' && $method === 'hash'))) {
            throw new LogicException(sprintf('Index "%s": %s does not support the %s index method.', $this->name, $database, $method));
        }
        if ($this->operatorClass !== null && $database !== 'pgsql') {
            throw new LogicException(sprintf('Index "%s": operator classes are PostgreSQL only.', $this->name));
        }
        if ($this->statistics !== null && ($database !== 'pgsql' || $this->expression === null)) {
            throw new LogicException(sprintf('Index "%s": a statistics target needs PostgreSQL and an expression index.', $this->name));
        }
        $class = $this->operatorClass === null ? '' : ' ' . $this->operatorClass;
        $keys = $this->expression !== null
            // MySQL marks a functional key part with its own parentheses.
            ? ($database === 'mysql' ? '(' . $this->expression . ')' : $this->expression . $class)
            : implode(', ', array_map(static fn (string $column): string => $dialect->quoteIdentifier($column) . $class, $this->columns));
        $name = $dialect->quoteIdentifier($this->name);
        $statements = [match (true) {
            $database === 'pgsql' && $method !== null => sprintf('CREATE %sINDEX %s ON %s USING %s (%s)', $this->unique ? 'UNIQUE ' : '', $name, $dialect->quoteIdentifier($table), $method, $keys),
            $database === 'mysql' && $method !== null => sprintf('CREATE %sINDEX %s ON %s (%s) USING %s', $this->unique ? 'UNIQUE ' : '', $name, $dialect->quoteIdentifier($table), $keys, strtoupper($method)),
            default => sprintf('CREATE %sINDEX %s ON %s (%s)', $this->unique ? 'UNIQUE ' : '', $name, $dialect->quoteIdentifier($table), $keys),
        }];
        if ($this->statistics !== null) {
            $statements[] = sprintf('ALTER INDEX %s ALTER COLUMN 1 SET STATISTICS %d', $name, $this->statistics);
        }

        return $statements;
    }
}
