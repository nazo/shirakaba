<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

use Shirakaba\Database\Dialect\Dialect;

/**
 * Reports risky changes in migrations before they run:
 *
 * - data loss: dropped tables and columns;
 * - breaking renames: code deployed before the migration still uses the old names;
 * - failures on existing rows: a NOT NULL column added without a default, a unique index
 *   on existing data;
 * - long locks: indexes and foreign keys added to existing tables (PostgreSQL can build
 *   indexes CONCURRENTLY, outside a transaction);
 * - raw SQL, which cannot be checked.
 *
 * Changes to tables the same migration creates are never reported.
 */
final readonly class Linter
{
    public function __construct(
        private Dialect $dialect,
    ) {}

    /**
     * @param array<string, Migration> $migrations
     * @return array<string, list<string>> migration ID => warnings
     */
    public function lint(array $migrations): array
    {
        $warnings = [];
        foreach ($migrations as $id => $migration) {
            $schema = new Schema($this->dialect);
            $migration->up($schema);
            $found = $this->warnings($schema->operations());
            if ($found !== []) {
                $warnings[$id] = $found;
            }
        }

        return $warnings;
    }

    /**
     * @param list<Operation> $operations
     * @return list<string>
     */
    private function warnings(array $operations): array
    {
        $created = [];
        $warnings = [];
        foreach ($operations as $operation) {
            if ($operation->kind === 'createTable') {
                $created[$operation->table] = true;
                continue;
            }
            if (isset($created[$operation->table])) {
                continue;
            }
            $warning = match ($operation->kind) {
                'dropTable' => sprintf('drops table "%s" and its data', $operation->table),
                'dropColumn' => sprintf('drops column "%s.%s" and its data', $operation->table, $operation->subject),
                'renameTable' => sprintf('renames table %s; code still running with the old name fails until it is deployed', $operation->subject),
                'renameColumn' => sprintf('renames column "%s" %s; code still running with the old name fails until it is deployed', $operation->table, $operation->subject),
                'addRequiredColumn' => sprintf('adds NOT NULL column "%s.%s" without a default, which fails on a table with rows', $operation->table, $operation->subject),
                'addUniqueIndex' => sprintf('adds unique index "%s" to existing table "%s", which fails if rows already conflict', $operation->subject, $operation->table),
                'addIndex' => sprintf(
                    'adds index "%s" to existing table "%s", which locks writes while it builds%s',
                    $operation->subject,
                    $operation->table,
                    $this->dialect->name() === 'pgsql' ? '; consider CREATE INDEX CONCURRENTLY in a migration of its own' : '',
                ),
                'addForeignKey' => sprintf('adds foreign key "%s" to existing table "%s", which checks every row while holding a lock', $operation->subject, $operation->table),
                'sql' => 'runs raw SQL, which the linter cannot check: ' . strtok($operation->subject, "\n"),
                default => null,
            };
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }
}
