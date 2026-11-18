<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\DatabaseBridge\Databases;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\DatabaseBridge\Internal\DbResolver;
use Shirakaba\DatabaseBridge\Internal\ScopedConnections;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\RequestDatabase;

#[CoversClass(Database::class)]
#[CoversClass(Databases::class)]
#[CoversClass(DatabaseMiddleware::class)]
#[CoversClass(Db::class)]
#[CoversClass(DbResolver::class)]
#[CoversClass(ScopedConnections::class)]
#[CoversClass(RequestDatabase::class)]
final class MultipleDatabasesTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * A database in its own SQLite file holding one row with $label.
     */
    private function database(string $label, ?Database $replica = null): Database
    {
        $file = sys_get_temp_dir() . '/shirakaba-multi-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->files[] = $file;
        $pdo = PDO::connect('sqlite:' . $file);
        $pdo->exec('CREATE TABLE labels (label TEXT)');
        $pdo->exec("INSERT INTO labels VALUES ('" . $label . "')");

        return new Database(static fn (): PDO => PDO::connect('sqlite:' . $file), replica: $replica);
    }

    private static function label(Reader $db): string
    {
        $label = $db->fetchValue('SELECT label FROM labels');

        return is_string($label) ? $label : '?';
    }

    public function testEachDatabaseLeasesItsOwnConnection(): void
    {
        $main = $this->database('main');
        $other = $this->database('other');

        $error = ScopedWork::run([], 'TEST', 'two', new Cancellation(), static function (RequestScope $scope) use ($main, $other): void {
            self::assertSame('main', self::label(RequestDatabase::reader($main, $scope)));
            self::assertSame('other', self::label(RequestDatabase::reader($other, $scope)));
            self::assertNotSame(RequestDatabase::connection($main, $scope), RequestDatabase::connection($other, $scope));
            self::assertSame(RequestDatabase::connection($main, $scope), RequestDatabase::connection($main, $scope));
        });

        self::assertNull($error);
    }

    public function testDbChoosesANamedDatabase(): void
    {
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($this->database('main'), ['analytics' => $this->database('analytics')]));
        $app->get('/both', static fn (#[Db] Reader $main, #[Db('analytics')] Reader $analytics): Text => new Text(self::label($main) . ' ' . self::label($analytics)));
        $app->get('/unknown', static fn (#[Db('missing')] Reader $db): Text => new Text(self::label($db)));
        $client = new TestClient($app->build());

        self::assertSame('main analytics', (string) $client->get('/both')->getBody());
        self::assertSame(500, $client->get('/unknown')->getStatusCode());
    }

    public function testSafeRequestsReadFromTheReplica(): void
    {
        // The replica lags: it still has "old" while the primary has "new".
        $primary = $this->database('new', replica: $this->database('old'));
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($primary));
        $app->get('/read', static fn (#[Db] Reader $db): Text => new Text(self::label($db)));
        $app->get('/current', static fn (#[Db(primary: true)] Reader $db): Text => new Text(self::label($db)));
        $app->post('/write', static fn (#[Db] Reader $db, #[Db] Writer $writer): Text => new Text(self::label($db) . ' ' . self::label($writer)));
        $client = new TestClient($app->build());

        self::assertSame('old', (string) $client->get('/read')->getBody());
        self::assertSame('new', (string) $client->get('/current')->getBody());
        self::assertSame('new new', (string) $client->post('/write')->getBody(), 'Other methods read from the primary.');
    }

    public function testARequestReadsItsOwnWrites(): void
    {
        $primary = $this->database('new', replica: $this->database('old'));

        $error = ScopedWork::run([], 'TEST', 'own-writes', new Cancellation(), static function (RequestScope $scope) use ($primary): void {
            self::assertSame('old', self::label(RequestDatabase::replicaReader($primary, $scope)));
            RequestDatabase::writer($primary, $scope)->execute("UPDATE labels SET label = 'newer'");
            self::assertSame('newer', self::label(RequestDatabase::replicaReader($primary, $scope)), 'After taking a Writer, reads go to the primary.');
        });

        self::assertNull($error);
    }
}
