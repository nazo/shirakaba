<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Scope\Provide;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\DatabaseBridge\Internal\DbResolver;
use Shirakaba\DatabaseBridge\Tests\Fixtures\StampedNotes;
use Shirakaba\Time\FrozenClock;
use Psr\Clock\ClockInterface;

#[CoversClass(Provide::class)]
#[CoversClass(Db::class)]
#[CoversClass(DbResolver::class)]
final class ProvideTest extends TestCase
{
    private static function app(bool $provide): AppBuilder
    {
        $database = new Database(static function (): PDO {
            $pdo = PDO::connect('sqlite::memory:');
            $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');

            return $pdo;
        });
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        if ($provide) {
            $app->middleware(new Provide([ClockInterface::class => new FrozenClock('2026-11-12T00:00:00Z')]));
        }
        $app->get('/summary', static fn (#[Db] StampedNotes $notes): Text => new Text($notes->summary()));

        return $app;
    }

    public function testDbClassesReceiveProvidedServices(): void
    {
        self::assertSame('0 at 2026-11-12', (string) new TestClient(self::app(true)->build())->get('/summary')->getBody());
    }

    public function testAMissingServiceFailsTheRequest(): void
    {
        self::assertSame(500, new TestClient(self::app(false)->build())->get('/summary')->getStatusCode());
    }

    public function testServicesMustMatchTheirTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Provide([ClockInterface::class => new \ArrayObject()]);
    }

    public function testDbClassParametersMustBeTyped(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (#[Db] Fixtures\UntypedService $db): Text => new Text(''));

        $this->expectException(InvalidApplication::class);
        $this->expectExceptionMessage('parameter $name must be typed with a class or interface');
        $app->build();
    }
}
