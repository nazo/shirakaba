<?php

declare(strict_types=1);

namespace Shirakaba\Database\Tests;

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;

#[CoversClass(Connection::class)]
final class NotificationTest extends TestCase
{
    /**
     * PDO::connect() gives a Pdo\Pgsql; `new PDO()` gives a plain PDO, as Swoole's coroutine
     * PostgreSQL driver does even through PDO::connect(). Both receive notifications.
     *
     * @return iterable<string, array{\Closure(string, ?string, ?string): PDO}>
     */
    public static function connections(): iterable
    {
        yield 'Pdo\Pgsql' => [static fn (string $dsn, ?string $user, ?string $password): PDO => PDO::connect($dsn, $user, $password)];
        yield 'plain PDO' => [static fn (string $dsn, ?string $user, ?string $password): PDO => new PDO($dsn, $user, $password)];
    }

    /**
     * @param \Closure(string, ?string, ?string): PDO $open
     */
    #[DataProvider('connections')]
    public function testReceivesNotificationsOnPostgreSql(\Closure $open): void
    {
        $dsn = getenv('SHIRAKABA_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '' || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Set SHIRAKABA_TEST_PGSQL_DSN to run against PostgreSQL.');
        }
        $user = getenv('SHIRAKABA_TEST_PGSQL_USER');
        $password = getenv('SHIRAKABA_TEST_PGSQL_PASSWORD');
        $database = new Database(static fn (): PDO => $open($dsn, is_string($user) ? $user : null, is_string($password) ? $password : null));
        $connection = $database->connect();
        $writer = $connection->writer();

        self::assertTrue($connection->receivesNotifications());
        $writer->execute('LISTEN shirakaba_probe');
        self::assertNull($connection->waitForNotification(10));
        $writer->execute("NOTIFY shirakaba_probe, 'hello'");

        self::assertSame(['channel' => 'shirakaba_probe', 'payload' => 'hello'], $connection->waitForNotification(1000));
        $writer->execute('UNLISTEN *');
    }

    public function testOtherDatabasesHaveNoNotifications(): void
    {
        $connection = new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect();

        self::assertFalse($connection->receivesNotifications());
        $this->expectException(LogicException::class);
        $connection->waitForNotification(10);
    }
}
