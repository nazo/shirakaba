<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

/**
 * A column being defined in a Blueprint. Modifiers return the column for chaining.
 */
final class Column
{
    private bool $nullable = false;
    private bool $hasDefault = false;
    private string|int|float|bool|null $default = null;
    private ?string $defaultSql = null;
    private bool $unique = false;

    /** @var array<string, string> dialect name => collation */
    private array $collations = [];

    /**
     * @param array<string, string> $types dialect name => SQL type
     */
    public function __construct(
        public readonly string $name,
        private readonly array $types,
        public readonly bool $primaryKey = false,
    ) {}

    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    public function default(string|int|float|bool|null $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /**
     * A default given as SQL, such as "CURRENT_TIMESTAMP"; it is written as is.
     */
    public function defaultSql(string $sql): self
    {
        $this->defaultSql = $sql;

        return $this;
    }

    /**
     * Collations per dialect, such as ['mysql' => 'utf8mb4_bin', 'pgsql' => 'C']. Dialects
     * left out keep the database default.
     *
     * @param array<string, string> $collations dialect name => collation name
     * @throws \InvalidArgumentException when a name is not a plain identifier
     */
    public function collation(array $collations): self
    {
        foreach ($collations as $dialect => $collation) {
            if (preg_match('/^[A-Za-z0-9_.\-]+$/D', $collation) !== 1) {
                throw new \InvalidArgumentException(sprintf('Column "%s": "%s" is not a collation name.', $this->name, $collation));
            }
            $this->collations[$dialect] = $collation;
        }

        return $this;
    }

    /**
     * Compares and sorts the column's values by their bytes, case-sensitively and
     * independent of locale: utf8mb4_bin on MySQL, "C" on PostgreSQL and BINARY on SQLite.
     * Suits identifiers whose order matters, such as time-ordered string IDs.
     */
    public function bytewise(): self
    {
        return $this->collation(['mysql' => 'utf8mb4_bin', 'pgsql' => 'C', 'sqlite' => 'BINARY']);
    }

    /**
     * Adds a unique index on this column alone.
     */
    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault || $this->defaultSql !== null;
    }

    /**
     * @internal
     */
    public function definition(\Shirakaba\Database\Dialect\Dialect $dialect): string
    {
        $type = $this->types[$dialect->name()] ?? throw new \LogicException(sprintf('Column "%s" has no type for %s.', $this->name, $dialect->name()));
        $sql = $dialect->quoteIdentifier($this->name) . ' ' . $type;
        if (isset($this->collations[$dialect->name()])) {
            $sql .= ' COLLATE ' . $dialect->quoteIdentifier($this->collations[$dialect->name()]);
        }
        if ($this->primaryKey) {
            return $sql;
        }
        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';
        if ($this->defaultSql !== null) {
            $sql .= ' DEFAULT ' . $this->defaultSql;
        } elseif ($this->hasDefault) {
            $sql .= ' DEFAULT ' . self::literal($this->default, $dialect->name() === 'mysql');
        }

        return $sql;
    }

    /**
     * DDL cannot bind parameters, so defaults are written as literals. MySQL also treats a
     * backslash in a string as an escape character (unless NO_BACKSLASH_ESCAPES is set).
     */
    private static function literal(string|int|float|bool|null $value, bool $backslashEscapes): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => (string) $value,
            default => "'" . str_replace("'", "''", $backslashEscapes ? str_replace('\\', '\\\\', $value) : $value) . "'",
        };
    }
}
