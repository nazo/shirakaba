<?php

declare(strict_types=1);

namespace Shirakaba\SessionDatabase\Tests;

use DateTimeImmutable;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\RequestDatabase;
use Shirakaba\Migrations\Migrations;
use Shirakaba\Migrations\Migrator;
use Shirakaba\Session\Session;
use Shirakaba\Session\SessionMiddleware;
use Shirakaba\Session\Testing\SessionStoreChecks;
use Shirakaba\SessionDatabase\CreateSessionsTable;
use Shirakaba\SessionDatabase\DatabaseSessionStore;
use Shirakaba\Time\FrozenClock;

#[CoversClass(DatabaseSessionStore::class)]
#[CoversClass(CreateSessionsTable::class)]
final class DatabaseSessionStoreTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        $databases = TestDatabases::available();
        $file = tempnam(sys_get_temp_dir(), 'shirakaba-sessions');
        if ($file === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }
        register_shutdown_function(static fn () => @unlink($file));
        // Several connections must see the same SQLite data, which an in-memory database cannot.
        $databases['sqlite'] = [new Database(static fn (): PDO => PDO::connect('sqlite:' . $file))];

        return $databases;
    }

    private static function migrate(Database $database): void
    {
        $connection = $database->connect();
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_sessions');
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_migrations');
        Migrator::for(new Migrations($database, ['2026_11_18_create_sessions' => new CreateSessionsTable()]))->migrate();
    }

    /**
     * @param \Closure(RequestScope): void $work
     */
    private static function inScope(\Closure $work): void
    {
        $error = ScopedWork::run([], 'TEST', 'sessions', new Cancellation(), $work);
        if ($error !== null) {
            throw $error;
        }
    }

    #[DataProvider('databases')]
    public function testPassesTheStoreChecks(Database $database): void
    {
        self::migrate($database);
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-18T00:00:00Z'));
        $store = new DatabaseSessionStore($database, $clock);

        self::inScope(static function (RequestScope $scope) use ($store, $clock): void {
            self::assertSame([], SessionStoreChecks::run($store, $clock, $scope));
        });
    }

    #[DataProvider('databases')]
    public function testInstancesShareSessionsAndTheTableHoldsNoIds(Database $database): void
    {
        self::migrate($database);
        $client = static function () use ($database): TestClient {
            $app = new AppBuilder();
            $app->middleware(new SessionMiddleware(new DatabaseSessionStore($database)));
            $app->get('/count', static function (RequestScope $scope): Text {
                $session = Session::of($scope);
                $count = $session->get('count', 0);
                $next = is_int($count) ? $count + 1 : 1;
                $session->set('count', $next);

                return new Text((string) $next);
            });

            return new TestClient($app->build());
        };
        $first = $client()->get('/count');
        $id = self::sessionId($first);
        self::assertNotNull($id);

        $second = $client()->get('/count', ['Cookie' => '__Host-session=' . $id]);

        self::assertSame('2', (string) $second->getBody(), 'Another instance continues the session.');
        $rows = $database->connect()->reader()->fetchAll('SELECT id_hash, data FROM shirakaba_sessions');
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $id), $rows[0]['id_hash'] ?? null);
        self::assertStringNotContainsString($id, serialize($rows), 'The table never holds a session ID.');
    }

    #[DataProvider('databases')]
    public function testARolledBackRequestKeepsItsSession(Database $database): void
    {
        self::migrate($database);
        $app = new AppBuilder();
        $app->middleware(new SessionMiddleware(new DatabaseSessionStore($database)));
        $app->post('/fail', static function (RequestScope $scope) use ($database): Text {
            Session::of($scope)->set('user', 'alice');
            try {
                RequestDatabase::writer($database, $scope)->transaction(static function (Writer $db): never {
                    $db->execute("DELETE FROM shirakaba_sessions");
                    throw new RuntimeException('rolled back');
                });
            } catch (RuntimeException) {
                // The handler recovers; its transaction was rolled back.
            }

            return new Text('ok');
        });
        $id = self::sessionId(new TestClient($app->build())->post('/fail'));

        self::assertNotNull($id);
        self::assertEquals(1, $database->connect()->reader()->fetchValue('SELECT COUNT(*) FROM shirakaba_sessions'));
    }

    public function testRefusesToSaveInsideATransaction(): void
    {
        $database = self::databases()['sqlite'][0] ?? self::fail('SQLite is always available.');
        self::migrate($database);
        $store = new DatabaseSessionStore($database);

        $this->expectException(LogicException::class);
        self::inScope(static function (RequestScope $scope) use ($store, $database): void {
            RequestDatabase::writer($database, $scope)->transaction(static fn () => $store->save($scope, Session::newId(), ['a' => 1], 60));
        });
    }

    #[DataProvider('databases')]
    public function testPrunesExpiredSessions(Database $database): void
    {
        self::migrate($database);
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-18T00:00:00Z'));
        $store = new DatabaseSessionStore($database, $clock);
        $live = Session::newId();
        self::inScope(static function (RequestScope $scope) use ($store, $live): void {
            $store->save($scope, Session::newId(), ['user' => 'bob'], 60);
            $store->save($scope, $live, ['user' => 'alice'], 120);
        });
        $clock->advance(60);

        self::assertSame(1, $store->prune($database->connect()->writer()));
        self::inScope(static function (RequestScope $scope) use ($store, $live): void {
            self::assertSame(['user' => 'alice'], $store->load($scope, $live));
        });
    }

    private static function sessionId(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (preg_match('/^__Host-session=([^;]*)/', $cookie, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
