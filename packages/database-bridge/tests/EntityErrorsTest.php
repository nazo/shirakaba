<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Database\Database;
use Shirakaba\Database\Reader;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\DatabaseBridge\Tests\Fixtures\Shelf;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\StaleEntity;

/**
 * The ORM throws plain exceptions; the bridge answers them as HTTP errors (design/08-database.md).
 */
#[CoversClass(DatabaseMiddleware::class)]
final class EntityErrorsTest extends TestCase
{
    private static function client(): TestClient
    {
        $file = tempnam(sys_get_temp_dir(), 'shirakaba-bridge');
        self::assertIsString($file);
        register_shutdown_function(static fn () => @unlink($file));
        $database = new Database(static fn (): PDO => PDO::connect('sqlite:' . $file));
        $database->connect()->writer()->execute('CREATE TABLE bridge_shelves (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        $app->get('/shelves/{id}', static fn (#[Path] int $id, #[Db] Reader $db): Text => new Text(Entities::get(Shelf::class, $id, $db)->name));
        $app->get('/stale', static fn (): Text => throw StaleEntity::of('shelf'));

        return new TestClient($app->build());
    }

    public function testAMissingEntityIsA404(): void
    {
        $response = self::client()->get('/shelves/7');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('The requested resource was not found.', (string) $response->getBody(), 'Without a #[Table] label the class name stays internal.');
    }

    public function testAStaleEntityIsA409(): void
    {
        $response = self::client()->get('/stale');

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('The shelf was changed by someone else', (string) $response->getBody());
    }
}
