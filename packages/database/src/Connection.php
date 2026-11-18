<?php

declare(strict_types=1);

namespace Shirakaba\Database;

use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Observe\DatabaseEvent;
use Shirakaba\Database\Observe\EventSink;
use Shirakaba\Time\LocalDate;
use Throwable;

/**
 * One open connection with its dialect. Database::lease() lends one from the pool (a request
 * gets one through shirakaba/database-bridge); scripts and tests open their own with
 * Database::connect().
 *
 * Statements run here, so parameters are bound with their types, driver errors become
 * DatabaseError subclasses, and nested transactions use savepoints.
 */
final class Connection
{
    private int $depth = 0;
    private bool $released = false;

    /**
     * The statement time limit set on the session, in milliseconds; 0 for the default, null
     * when unknown after a rollback.
     */
    private ?int $limit = 0;
    private bool $writer = false;

    /** @var array<string, string> session settings the lease asked for (design/08-database.md) */
    private array $settings = [];

    /** @var array<string, string>|null the settings applied, null when unknown after a rollback */
    private ?array $appliedSettings = [];
    private int $statements = 0;

    /** @var list<\Closure(): void> callbacks waiting for the outermost commit */
    private array $afterCommit = [];
    private float $seconds = 0.0;

    /**
     * @param EventSink|null $events receives a StatementExecuted for every statement
     * @param bool $leased whether the connection belongs to a Lease, after whose release it
     *                     must refuse statements
     * @internal created by Database
     */
    public function __construct(
        public readonly PDO $pdo,
        public readonly Dialect $dialect,
        private readonly ?EventSink $events = null,
        private readonly bool $leased = false,
    ) {}

    /**
     * How many statements ran on this connection, and for how long in total.
     *
     * @return array{int, float} count, seconds
     */
    public function statistics(): array
    {
        return [$this->statements, $this->seconds];
    }

    public function reader(): Reader
    {
        return new Reader($this);
    }

    public function writer(): Writer
    {
        $this->writer = true;

        return new Writer($this);
    }

    /**
     * Whether a Writer was taken on this connection, after which a request reads its own
     * writes from it rather than from a replica (design/08-database.md).
     */
    public function hasWriter(): bool
    {
        return $this->writer;
    }

    /**
     * @param array<string|int, scalar|\DateTimeImmutable|LocalDate|\BackedEnum|null> $parameters
     * @throws DatabaseError when the statement fails
     * @internal
     * @phpstan-impure io.db, nondet.time
     */
    public function run(string $sql, array $parameters, ?float $timeout = null): PDOStatement
    {
        if ($this->released) {
            // The PDO may serve another request now.
            throw new \LogicException('The connection was released with its lease; a Reader or Writer must not outlive the lease (for a request, the request itself).');
        }
        $this->applySettings();
        $this->applyLimit($timeout === null ? 0 : max(1, (int) ceil($timeout * 1000)));

        return $this->execute($sql, $parameters);
    }

    /**
     * Sets a custom session setting for every following statement of this lease, such as the
     * tenant a PostgreSQL row-level security policy reads with current_setting() (design/08-database.md).
     * It is applied before the next statement, only when it changed, again after a rollback,
     * and never outlives the lease: a released connection is reset before it is reused.
     *
     * @param string|null $value null for the empty string, which current_setting(name, true)
     *                           also returns for a setting that was never set
     * @throws \InvalidArgumentException for a name that is not "prefix.name" in lower case
     * @throws \LogicException on databases without session settings (MySQL, SQLite)
     */
    public function setSetting(string $name, ?string $value): void
    {
        if (preg_match('/\A[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*\z/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a custom setting; use "prefix.name" in lower case, such as "app.organization_id".', $name));
        }
        if ($this->dialect->sessionSetting() === null) {
            throw new \LogicException(sprintf('%s has no session settings for row-level security; filter with ORM scopes instead.', $this->dialect->name()));
        }
        $this->settings[$name] = $value ?? '';
    }

    /**
     * @throws DatabaseError
     */
    private function applySettings(): void
    {
        $statement = $this->dialect->sessionSetting();
        if ($this->settings === [] || $statement === null) {
            return;
        }
        $applied = $this->appliedSettings ?? [];
        foreach ($this->settings as $name => $value) {
            if ($this->appliedSettings !== null && ($applied[$name] ?? null) === $value) {
                continue;
            }
            $this->guard(function () use ($statement, $name, $value): void {
                $prepared = $this->pdo->prepare($statement);
                if ($prepared === false) {
                    throw new PDOException('Unable to prepare the setting.');
                }
                $prepared->execute([$name, $value]);
            });
            $applied[$name] = $value;
        }
        $this->appliedSettings = $applied;
    }

    /**
     * Sets the session's statement time limit only when it changes: statements
     * with the same limit share one SET, and the first statement without a limit resets it,
     * so a limit never applies to a statement that did not ask for it.
     *
     * @throws DatabaseError when the database refuses the setting
     */
    private function applyLimit(int $milliseconds): void
    {
        if ($this->limit === $milliseconds) {
            return;
        }
        $statements = $this->dialect->statementTimeout(max(1, $milliseconds));
        if ($statements === null) {
            // The database has no time limit (SQLite); nothing to set or reset.
            $this->limit = $milliseconds;

            return;
        }
        $this->guard(fn () => $this->pdo->exec($milliseconds === 0 ? $statements[1] : $statements[0]));
        $this->limit = $milliseconds;
    }

    /**
     * @param array<string|int, scalar|\DateTimeImmutable|LocalDate|\BackedEnum|null> $parameters
     * @throws DatabaseError when the statement fails
     */
    private function execute(string $sql, array $parameters): PDOStatement
    {
        $started = hrtime(true);
        try {
            return $this->guard(function () use ($sql, $parameters): PDOStatement {
                $statement = $this->pdo->prepare($sql);
                if ($statement === false) {
                    throw new PDOException('Unable to prepare the statement.');
                }
                foreach ($parameters as $key => $value) {
                    $statement->bindValue(is_int($key) ? $key + 1 : $key, ...$this->bindable($value));
                }
                $statement->execute();

                return $statement;
            });
        } finally {
            $seconds = (hrtime(true) - $started) / 1e9;
            $this->statements++;
            $this->seconds += $seconds;
            $this->events?->record(new StatementExecuted($sql, $seconds, $this->dialect->name()));
        }
    }

    /**
     * @template T
     * @param \Closure(Writer): T $work
     * @return T
     * @internal
     * @phpstan-impure io.db
     */
    public function transaction(\Closure $work, Writer $writer): mixed
    {
        $savepoint = $this->depth === 0 ? null : 'shirakaba_' . $this->depth;
        $pending = count($this->afterCommit);
        $this->guard(fn () => $savepoint === null ? $this->pdo->beginTransaction() : $this->pdo->exec('SAVEPOINT ' . $savepoint));
        $this->depth++;
        try {
            $result = $work($writer);
        } catch (Throwable $e) {
            $this->depth--;
            // Callbacks registered by the rolled-back work are dropped with it.
            $this->afterCommit = array_slice($this->afterCommit, 0, $pending);
            $this->guard(fn () => $savepoint === null ? $this->pdo->rollBack() : $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint));
            // A rollback undoes a SET made inside the transaction on PostgreSQL.
            $this->limit = null;
            $this->appliedSettings = null;

            throw $e;
        }
        $this->depth--;
        try {
            $this->guard(fn () => $savepoint === null ? $this->pdo->commit() : $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint));
        } catch (Throwable $e) {
            $this->afterCommit = array_slice($this->afterCommit, 0, $pending);

            throw $e;
        }
        if ($savepoint === null) {
            $callbacks = $this->afterCommit;
            $this->afterCommit = [];
            foreach ($callbacks as $callback) {
                $callback();
            }
        }

        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Runs $callback once the outermost transaction commits, or at once outside a
     * transaction. A rollback discards it, so work that must follow committed data (cache
     * invalidation, messages) never follows data that was rolled back.
     *
     * @param \Closure(): void $callback
     */
    public function afterCommit(\Closure $callback): void
    {
        if ($this->depth === 0) {
            $callback();

            return;
        }
        $this->afterCommit[] = $callback;
    }

    /**
     * Reports an event to whoever leased the connection, such as the request's observers.
     */
    public function record(DatabaseEvent $event): void
    {
        $this->events?->record($event);
    }

    /**
     * Rolls back what a lease left open: a request that ends inside a transaction failed,
     * and nothing is committed implicitly.
     *
     * @internal
     */
    public function release(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->depth = 0;
        $this->afterCommit = [];
        $this->released = $this->leased;
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     * @throws DatabaseError
     */
    private function guard(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException $e) {
            throw $this->dialect->classify($e) ?? new DatabaseError($e);
        }
    }

    /**
     * @return array{mixed, int}
     */
    private function bindable(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_bool($value) => [$value, PDO::PARAM_BOOL],
            $value instanceof DateTimeImmutable => [$this->dialect->formatInstant($value), PDO::PARAM_STR],
            $value instanceof LocalDate => [$value->toString(), PDO::PARAM_STR],
            $value instanceof \BackedEnum => $this->bindable($value->value),
            is_float($value) => [(string) $value, PDO::PARAM_STR],
            is_string($value) => [$value, PDO::PARAM_STR],
            default => throw new \InvalidArgumentException(sprintf('Unable to bind a %s parameter.', get_debug_type($value))),
        };
    }
}
