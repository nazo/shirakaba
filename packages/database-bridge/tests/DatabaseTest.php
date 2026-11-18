<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests;

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\RequestDatabase;

#[CoversClass(Database::class)]
#[CoversClass(DatabaseMiddleware::class)]
#[CoversClass(Db::class)]
#[CoversClass(Reader::class)]
#[CoversClass(Writer::class)]
#[CoversClass(Connection::class)]
final class DatabaseTest extends TestCase
{
    private string $file;
    private int $connections = 0;

    protected function setUp(): void
    {
        // A file, not :memory:, so that every request's own connection sees the same data.
        $this->file = sys_get_temp_dir() . '/shirakaba-db-' . bin2hex(random_bytes(6)) . '.sqlite';
        new PDO('sqlite:' . $this->file)->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function client(): TestClient
    {
        $database = new Database(function (): PDO {
            $this->connections++;

            return new PDO('sqlite:' . $this->file);
        });
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        $app->get('/notes', static fn (#[Db] Reader $db): Text => new Text(implode(',', array_map(
            static fn (array $row): string => is_string($row['body'] ?? null) ? $row['body'] : '?',
            $db->fetchAll('SELECT body FROM notes ORDER BY id'),
        ))));
        $app->get('/count', static fn (#[Db] Reader $db): Text => new Text(var_export($db->fetchValue('SELECT COUNT(*) FROM notes'), true)));
        $app->post('/notes', static function (#[Db] Writer $db): Text {
            $db->execute('INSERT INTO notes (body) VALUES (?)', ['hello']);

            return new Text($db->lastInsertId());
        });
        $app->post('/fail', static fn (#[Db] Writer $db): Text => new Text((string) $db->transaction(static function (Writer $db): int {
            $inserted = $db->execute('INSERT INTO notes (body) VALUES (?)', ['lost']);

            return $inserted === 1 ? throw new LogicException('rolled back') : $inserted;
        })));
        $app->post('/abandon', static function (RequestScope $scope): Text {
            $pdo = RequestDatabase::connection($scope->get(Database::class), $scope)->pdo;
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO notes (body) VALUES ('abandoned')");

            return new Text('left a transaction open');
        });
        $app->get('/static', static fn (): Text => new Text('no database'));

        return new TestClient($app->build());
    }

    public function testReadsAndWritesWithOneConnectionPerRequest(): void
    {
        $client = $this->client();

        self::assertSame('1', (string) $client->post('/notes')->getBody());
        self::assertSame('2', (string) $client->post('/notes')->getBody());
        self::assertSame('hello,hello', (string) $client->get('/notes')->getBody());
        self::assertSame(3, $this->connections);
    }

    public function testRequestsWithoutDatabaseOpenNoConnection(): void
    {
        $this->client()->get('/static');

        self::assertSame(0, $this->connections);
    }

    public function testFailedTransactionsRollBack(): void
    {
        $client = $this->client();

        self::assertSame(500, $client->post('/fail')->getStatusCode());
        self::assertSame('0', (string) $client->get('/count')->getBody());
    }

    public function testTransactionsLeftOpenAreRolledBackWhenTheRequestEnds(): void
    {
        $client = $this->client();
        $client->post('/abandon');

        self::assertSame('0', (string) $client->get('/count')->getBody());
    }

    public function testDbParametersAreInternalInDescriptions(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (#[Db] Reader $db): Text => new Text(''));

        self::assertSame(ParameterDescription::INTERNAL, ($app->describe()->routes[0]->parameters[0] ?? null)?->source);
    }

    public function testDbNeedsReaderOrWriter(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (#[Db] PDO $db): Text => new Text(''));

        $this->expectException(InvalidApplication::class);
        $app->build();
    }
}
