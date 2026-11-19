<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests;

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Database\Connection;
use Shirakaba\Database\ConnectionLeased;
use Shirakaba\Database\Database;
use Shirakaba\Database\Dialect\MySql;
use Shirakaba\Database\Dialect\PostgreSql;
use Shirakaba\Database\Dialect\Sqlite;
use Shirakaba\Database\Internal\ConnectionPool;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\DatabaseBridge\RecordedDatabaseEvent;
use Shirakaba\DatabaseBridge\RequestDatabase;
use Throwable;

#[CoversClass(Database::class)]
#[CoversClass(RequestDatabase::class)]
#[CoversClass(RecordedDatabaseEvent::class)]
#[CoversClass(Connection::class)]
#[CoversClass(ConnectionPool::class)]
#[CoversClass(ConnectionLeased::class)]
#[CoversClass(PostgreSql::class)]
#[CoversClass(MySql::class)]
#[CoversClass(Sqlite::class)]
final class ConnectionReuseTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function databases(): array
    {
        $names = array_keys(TestDatabases::available());

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    /**
     * A database like TestDatabases' one, counting connections, with reuse on.
     *
     * @param \ArrayObject<int, int> $opened
     */
    private static function database(string $name, \ArrayObject $opened, int $maxUses = 10_000): Database
    {
        $source = TestDatabases::available()[$name][0] ?? self::fail('Unknown database.');
        $file = sys_get_temp_dir() . '/shirakaba-reuse-' . getmypid() . '.sqlite';
        $connect = $name === 'sqlite'
            ? static fn (): PDO => PDO::connect('sqlite:' . $file)
            : static fn (): PDO => $source->connect()->pdo;

        return new Database(
            static function () use ($connect, $opened): PDO {
                $opened->append(1);

                return $connect();
            },
            reuse: true,
            maxUses: $maxUses,
        );
    }

    /**
     * Runs $work like a request of a worker runtime, returning the events reported.
     *
     * @param \Closure(RequestScope): void $work
     * @return list<ConnectionLeased>
     */
    private static function request(\Closure $work): array
    {
        $events = new \ArrayObject();
        $observer = new class($events) implements Observer {
            /** @param \ArrayObject<int, Event> $events */
            public function __construct(private \ArrayObject $events) {}

            public function begin(RequestSummary $request): Observation
            {
                return new class($this->events) implements Observation {
                    /** @param \ArrayObject<int, Event> $events */
                    public function __construct(private \ArrayObject $events) {}

                    public function matched(RouteSummary $route): void {}

                    public function handlerFinished(): void {}

                    public function failed(Throwable $error): void {}

                    public function cleanupFailed(Throwable $error): void {}

                    public function recorded(Event $event): void
                    {
                        $this->events->append($event);
                    }

                    public function responseReady(int $status): void {}
                };
            }
        };
        $error = ScopedWork::run([$observer], 'TEST', 'reuse', new Cancellation(), $work);
        if ($error !== null && !$error instanceof \RuntimeException) {
            throw $error;
        }
        $leases = [];
        foreach ($events as $event) {
            if ($event instanceof RecordedDatabaseEvent && $event->event instanceof ConnectionLeased) {
                $leases[] = $event->event;
            }
        }

        return $leases;
    }

    private static function rowCount(PDO $pdo, string $sql): int
    {
        $value = self::value($pdo, $sql);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function value(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    #[DataProvider('databases')]
    public function testReusesOneConnectionAndResetsIt(string $name): void
    {
        $opened = new \ArrayObject();
        $database = self::database($name, $opened);
        $kept = null;

        // A request that leaves a transaction open, changes its session and fails.
        $first = self::request(static function (RequestScope $scope) use ($database, $name, &$kept): void {
            $connection = RequestDatabase::connection($database, $scope);
            $kept = $connection->reader();
            $pdo = $connection->pdo;
            $pdo->beginTransaction();
            match ($name) {
                'pgsql' => [
                    $pdo->exec("SET TIME ZONE 'Asia/Tokyo'"),
                    $pdo->exec("SET application_name = 'leaky'"),
                    $pdo->exec('LISTEN shirakaba_reuse'),
                    $pdo->exec('CREATE TEMPORARY TABLE shirakaba_reuse_temp (id INT)'),
                    $pdo->query('SELECT pg_advisory_lock(424242)'),
                ],
                'mysql' => [$pdo->exec("SET time_zone = '+09:00'"), $pdo->query("SELECT GET_LOCK('shirakaba_reuse', 0)")],
                default => [$pdo->exec('PRAGMA foreign_keys = OFF')],
            };
            throw new \RuntimeException('The request failed halfway.');
        });

        $second = self::request(static function (RequestScope $scope) use ($database, $name): void {
            $connection = RequestDatabase::connection($database, $scope);
            $pdo = $connection->pdo;
            self::assertFalse($pdo->inTransaction(), 'The open transaction was rolled back.');
            match ($name) {
                'pgsql' => (static function () use ($pdo): void {
                    self::assertSame('UTC', self::value($pdo, 'SHOW TIME ZONE'));
                    self::assertSame('', self::value($pdo, 'SHOW application_name'));
                    self::assertSame(0, self::rowCount($pdo, 'SELECT COUNT(*) FROM pg_listening_channels()'));
                    self::assertSame(0, self::rowCount($pdo, "SELECT COUNT(*) FROM pg_tables WHERE tablename = 'shirakaba_reuse_temp'"));
                    self::assertSame(0, self::rowCount($pdo, "SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
                })(),
                'mysql' => (static function () use ($pdo): void {
                    self::assertSame('+00:00', self::value($pdo, 'SELECT @@session.time_zone'));
                    self::assertNull(self::value($pdo, "SELECT IS_USED_LOCK('shirakaba_reuse')"));
                })(),
                default => self::assertSame(1, self::rowCount($pdo, 'PRAGMA foreign_keys')),
            };
        });

        self::assertCount(1, $opened, 'The second request reused the connection.');
        self::assertFalse(($first[0] ?? self::fail('No lease reported.'))->reused);
        self::assertTrue(($second[0] ?? self::fail('No lease reported.'))->reused);
        self::assertSame(1, $second[0]->previousUses);

        self::assertInstanceOf(Reader::class, $kept);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('released with its lease');
        $kept->fetchValue('SELECT 1');
    }

    #[DataProvider('databases')]
    public function testOpensANewConnectionAfterMaxUses(string $name): void
    {
        $opened = new \ArrayObject();
        $database = self::database($name, $opened, maxUses: 2);
        for ($request = 0; $request < 5; $request++) {
            self::request(static function (RequestScope $scope) use ($database): void {
                RequestDatabase::connection($database, $scope)->reader()->fetchValue('SELECT 1');
            });
        }

        self::assertCount(3, $opened, 'Two requests per connection.');
    }

    public function testWithoutReuseEveryRequestConnects(): void
    {
        $opened = new \ArrayObject();
        $database = new Database(static function () use ($opened): PDO {
            $opened->append(1);

            return PDO::connect('sqlite::memory:');
        });
        for ($request = 0; $request < 3; $request++) {
            self::request(static function (RequestScope $scope) use ($database): void {
                RequestDatabase::connection($database, $scope)->reader()->fetchValue('SELECT 1');
            });
        }

        self::assertCount(3, $opened);
    }
}
