<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

use LogicException;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Writer;
use Shirakaba\Query\Delete;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Select;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Applies and rolls back migrations on one connection.
 *
 * - Applied IDs are recorded in shirakaba_migrations.
 * - On MySQL and PostgreSQL an advisory lock keeps two deployments from migrating at once;
 *   SQLite serialises writers itself.
 * - On PostgreSQL and SQLite each migration runs in a transaction, so a failing migration
 *   leaves nothing behind. MySQL commits DDL implicitly: a migration that fails halfway must
 *   be repaired by hand, so keep MySQL migrations to one change each.
 */
final readonly class Migrator
{
    private const string TABLE = 'shirakaba_migrations';

    /**
     * @param array<string, Migration> $migrations in order
     */
    public function __construct(
        private Connection $connection,
        private array $migrations,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public static function for(Migrations $migrations): self
    {
        return new self($migrations->database->connect(), $migrations->migrations);
    }

    /**
     * @return array<string, bool> migration ID => applied
     * @throws DatabaseError
     */
    public function status(): array
    {
        $applied = $this->applied();
        $status = [];
        foreach (array_keys($this->migrations) as $id) {
            $status[$id] = isset($applied[$id]);
        }

        return $status;
    }

    /**
     * Applies every pending migration in order.
     *
     * @return array<string, list<string>> applied (or, in a dry run, pending) ID => statements
     * @throws DatabaseError
     * @throws LogicException when the database records migrations this code does not list
     */
    public function migrate(bool $dryRun = false): array
    {
        return $this->locked(function () use ($dryRun): array {
            $applied = $this->applied();
            $unknown = array_diff(array_keys($applied), array_keys($this->migrations));
            if ($unknown !== []) {
                throw new LogicException(sprintf('The database has migrations this code does not list: %s.', implode(', ', $unknown)));
            }
            $done = [];
            foreach ($this->migrations as $id => $migration) {
                if (isset($applied[$id])) {
                    continue;
                }
                $schema = new Schema($this->connection->dialect);
                $migration->up($schema);
                $done[$id] = $schema->statements();
                if (!$dryRun) {
                    $now = $this->clock->now();
                    $this->run($schema->statements(), static fn (Writer $db) => Insert::into(self::TABLE)->values(['id' => $id, 'applied_at' => $now])->execute($db));
                }
            }

            return $done;
        }, $dryRun);
    }

    /**
     * Undoes the most recently applied migrations, newest first.
     *
     * @param positive-int $steps
     * @return array<string, list<string>> rolled back ID => statements
     * @throws DatabaseError
     * @throws LogicException when a migration to undo is not Reversible
     */
    public function rollback(int $steps = 1, bool $dryRun = false): array
    {
        return $this->locked(function () use ($steps, $dryRun): array {
            $applied = $this->applied();
            $ids = array_values(array_filter(array_reverse(array_keys($this->migrations)), static fn (string $id): bool => isset($applied[$id])));
            $done = [];
            foreach (array_slice($ids, 0, $steps) as $id) {
                $migration = $this->migrations[$id] ?? throw new LogicException(sprintf('Unknown migration %s.', $id));
                if (!$migration instanceof Reversible) {
                    throw new LogicException(sprintf('Migration %s cannot be rolled back: it does not implement Reversible.', $id));
                }
                $schema = new Schema($this->connection->dialect);
                $migration->down($schema);
                $done[$id] = $schema->statements();
                if (!$dryRun) {
                    $this->run($schema->statements(), static fn (Writer $db) => Delete::from(self::TABLE)->where('id', '=', $id)->execute($db));
                }
            }

            return $done;
        }, $dryRun);
    }

    /**
     * Runs a migration's statements and records it, in one transaction where DDL is
     * transactional.
     *
     * @param list<string> $statements
     * @param \Closure(Writer): mixed $record
     */
    private function run(array $statements, \Closure $record): void
    {
        $work = static function (Writer $db) use ($statements, $record): void {
            foreach ($statements as $statement) {
                $db->execute($statement);
            }
            $record($db);
        };
        $db = $this->connection->writer();
        if ($this->connection->dialect->name() === 'mysql') {
            $work($db);

            return;
        }
        $db->transaction($work);
    }

    /**
     * @return array<string, true>
     */
    private function applied(): array
    {
        $db = $this->connection->writer();
        $instant = match ($db->dialect()->name()) {
            'mysql' => 'DATETIME(6)',
            'pgsql' => 'TIMESTAMPTZ(6)',
            default => 'TEXT',
        };
        $db->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id VARCHAR(191) NOT NULL PRIMARY KEY, applied_at %s NULL)',
            $db->dialect()->quoteIdentifier(self::TABLE),
            $instant,
        ));
        $applied = [];
        foreach (Select::from(self::TABLE)->columns('id')->fetchAll($db) as $row) {
            $id = $row['id'] ?? null;
            if (is_string($id)) {
                $applied[$id] = true;
            }
        }

        return $applied;
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function locked(\Closure $work, bool $dryRun): mixed
    {
        $db = $this->connection->writer();
        $dialect = $db->dialect()->name();
        if ($dryRun || $dialect === 'sqlite') {
            return $work();
        }
        if ($dialect === 'mysql') {
            $locked = $db->fetchValue("SELECT GET_LOCK('shirakaba_migrations', 60)");
            if ($locked !== 1 && $locked !== '1') {
                throw new LogicException('Another process is running migrations.');
            }
        } else {
            $db->execute('SELECT pg_advisory_lock(7270795)');
        }
        try {
            return $work();
        } finally {
            $db->execute($dialect === 'mysql' ? "SELECT RELEASE_LOCK('shirakaba_migrations')" : 'SELECT pg_advisory_unlock(7270795)');
        }
    }
}
