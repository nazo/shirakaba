<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Database\Database;
use Shirakaba\Database\Reader;
use Shirakaba\Database\StatementExecuted;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\DatabaseBridge\Testing\StatementLog;

#[CoversClass(StatementLog::class)]
#[CoversClass(StatementExecuted::class)]
final class StatementLogTest extends TestCase
{
    public function testCountsStatementsPerRequestWithoutValues(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'shirakaba-db');
        self::assertIsString($file);
        try {
            $database = new Database(static fn (): PDO => new PDO('sqlite:' . $file));
            $setup = $database->connect()->writer();
            $setup->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
            $setup->execute("INSERT INTO notes (body) VALUES ('a'), ('b'), ('c')");

            $log = new StatementLog();
            $app = new AppBuilder();
            $app->middleware(new DatabaseMiddleware($database));
            $app->observe($log);
            $app->get('/n-plus-one', static function (#[Db] Reader $db): Text {
                foreach ($db->fetchAll('SELECT id FROM notes ORDER BY id') as $row) {
                    $id = $row['id'] ?? null;
                    $db->fetchValue('SELECT body FROM notes WHERE id = ?', [is_int($id) ? $id : 0]);
                }

                return new Text('ok');
            });
            $app->get('/batched', static fn (#[Db] Reader $db): Text => new Text((string) count($db->fetchAll('SELECT id, body FROM notes'))));
            $client = new TestClient($app->build());

            $client->get('/n-plus-one');
            self::assertCount(4, $log->last(), $log->describe());
            self::assertSame(['SELECT body FROM notes WHERE id = ?' => 3], $log->repeated());
            self::assertStringContainsString('2. SELECT body FROM notes WHERE id = ?', $log->describe());

            $client->get('/batched');
            self::assertSame(['SELECT id, body FROM notes'], $log->last());
            self::assertSame([], $log->repeated());
        } finally {
            unlink($file);
        }
    }

    public function testEventAttributesFollowOpenTelemetryNames(): void
    {
        $event = new StatementExecuted('SELECT 1', 0.002, 'pgsql');

        self::assertSame('db.statement', $event->name());
        self::assertSame(0.002, $event->duration());
        self::assertSame(['db.system.name' => 'postgresql', 'db.query.text' => 'SELECT 1'], $event->attributes());
    }
}
