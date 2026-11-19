<?php

declare(strict_types=1);

namespace Shirakaba\Database;

use Closure;
use PDO;
use PDOException;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Dialect\Dialects;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Internal\ConnectionPool;
use Shirakaba\Database\Observe\EventSink;

/**
 * Application-scoped access to one database (design/03-runtimes-and-scopes.md). It holds only how to
 * connect; connections are lent with lease() and given back with Lease::release(), so
 * concurrent requests never share one. Requests lease through shirakaba/database-bridge,
 * which releases the lease when the request ends (design/08-database.md):
 *
 *     $database = new Database(static fn (): PDO => PDO::connect($dsn, $user, $password));
 *     $app->middleware(new DatabaseMiddleware($database));       // shirakaba/database-bridge
 *
 * Scripts and tests either lease or open a connection they own with connect().
 *
 * Each new connection gets its dialect's session settings: exceptions, native prepared
 * statements, UTC, strict SQL modes, SQLite foreign keys.
 *
 * In worker runtimes, `reuse: true` keeps a released connection for the next lease instead
 * of connecting again (design/08-database.md): it is rolled back and reset by the dialect first
 * (`DISCARD ALL` on PostgreSQL), dropped when that fails, after `maxUses` leases or
 * `maxLifetimeSeconds`, and checked with a round trip when it has been idle for a while.
 * Each lease reports a ConnectionLeased event saying which it got.
 *
 * An application may use several databases; each Database leases its own connections
 *. A Database may name a read replica (design/08-database.md), which the bridge reads from
 * until the request takes a Writer on the primary.
 */
final readonly class Database
{
    private ConnectionPool $pool;

    /**
     * @param Closure(): PDO $connect
     * @param Dialect|null $dialect detected from the PDO driver when null
     * @param bool $reuse keep the connection for the next request (worker runtimes)
     * @param float $maxIdleSeconds a connection idle longer than this is closed, not reused
     * @param float $maxLifetimeSeconds a connection open longer than this is closed after its request
     * @param int $maxUses requests one connection serves before it is closed
     * @param Database|null $replica a read replica of this database (design/08-database.md)
     */
    public function __construct(
        private Closure $connect,
        private ?Dialect $dialect = null,
        private bool $reuse = false,
        float $maxIdleSeconds = 300.0,
        float $maxLifetimeSeconds = 3600.0,
        int $maxUses = 10_000,
        public ?Database $replica = null,
    ) {
        $this->pool = new ConnectionPool($maxIdleSeconds, $maxLifetimeSeconds, $maxUses);
    }

    /**
     * Lends a connection from the pool, or a new one. The caller must release it.
     *
     * @param EventSink|null $events receives a ConnectionLeased now and a StatementExecuted
     *                               for every statement
     * @throws DatabaseError when connecting fails
     * @phpstan-impure io.db, nondet.time
     */
    public function lease(?EventSink $events = null): Lease
    {
        $idle = $this->reuse ? $this->pool->take() : null;
        if ($idle === null) {
            $started = hrtime(true);
            $connection = $this->open($events, true);
            $opened = hrtime(true);
            $uses = 0;
            $events?->record(new ConnectionLeased(false, ($opened - $started) / 1e9, $connection->dialect->name()));
        } else {
            [$pdo, $dialect, $opened, $uses] = $idle;
            $connection = new Connection($pdo, $dialect, $events, true);
            $events?->record(new ConnectionLeased(true, 0.0, $dialect->name(), $uses));
        }

        return new Lease($connection, function () use ($connection, $opened, $uses): void {
            $connection->release();
            if ($this->reuse) {
                $this->pool->give($connection->pdo, $connection->dialect, $opened, $uses + 1);
            }
        });
    }

    /**
     * A new connection owned by the caller, for scripts, migrations and tests.
     *
     * @throws DatabaseError when connecting fails
     * @phpstan-impure io.db
     */
    public function connect(): Connection
    {
        return $this->open(null, false);
    }

    /**
     * @throws DatabaseError when connecting fails
     */
    private function open(?EventSink $events, bool $leased): Connection
    {
        try {
            $pdo = ($this->connect)();
            $dialect = $this->dialect ?? Dialects::of($pdo);
            $dialect->configure($pdo);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw $this->dialect?->classify($e) ?? new DatabaseError($e);
        }

        return new Connection($pdo, $dialect, $events, $leased);
    }
}
