<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit\Tests;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\RequestDatabase;
use Shirakaba\Migrations\Migrator;
use Shirakaba\RateLimit\CreateRateLimitsTable;
use Shirakaba\RateLimit\DatabaseRateLimitStore;
use Shirakaba\RateLimit\RateLimiter;
use Shirakaba\RateLimit\TooManyRequests;
use Shirakaba\Time\FrozenClock;

#[CoversClass(DatabaseRateLimitStore::class)]
#[CoversClass(CreateRateLimitsTable::class)]
final class DatabaseRateLimitStoreTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        $databases = TestDatabases::available();
        // Each in-memory SQLite connection is its own database; requests need a shared file.
        $file = sys_get_temp_dir() . '/shirakaba-rate-limits-' . getmypid() . '.sqlite';
        $databases['sqlite'] = [new Database(static fn (): \PDO => \PDO::connect('sqlite:' . $file))];

        return $databases;
    }

    /**
     * @param \Closure(RequestScope): void $work
     */
    private static function inScope(\Closure $work): void
    {
        $error = ScopedWork::run([], 'TEST', 'rate', new Cancellation(), $work);
        if ($error !== null) {
            throw $error;
        }
    }

    #[DataProvider('databases')]
    public function testCountsAcrossRequestsAndPrunes(Database $database): void
    {
        $connection = $database->connect();
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_rate_limits');
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_migrations');
        new Migrator($connection, ['rate' => new CreateRateLimitsTable()])->migrate();
        $store = new DatabaseRateLimitStore($database);
        $clock = new FrozenClock('2026-11-14T00:00:00Z');
        $limiter = new RateLimiter($store, $clock);

        foreach ([true, true, false] as $allowed) {
            self::inScope(static fn (RequestScope $scope) => self::assertSame($allowed, $limiter->attempt($scope, 'sign-in', 2, 60, '192.0.2.1')->allowed));
        }
        $this->expectException(TooManyRequests::class);
        self::inScope(static function (RequestScope $scope) use ($limiter): void {
            $limiter->hit($scope, 'sign-in', 2, 60, '192.0.2.1');
        });
    }

    #[DataProvider('databases')]
    public function testNewWindowsPruningAndTransactions(Database $database): void
    {
        $connection = $database->connect();
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_rate_limits');
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_migrations');
        new Migrator($connection, ['rate' => new CreateRateLimitsTable()])->migrate();
        $store = new DatabaseRateLimitStore($database);
        $clock = new FrozenClock('2026-11-14T00:00:00Z');
        $limiter = new RateLimiter($store, $clock);

        self::inScope(static function (RequestScope $scope) use ($limiter): void {
            $limiter->hit($scope, 'posting', 1, 60, 'ada');
        });
        $clock->advance(60);
        self::inScope(static fn (RequestScope $scope) => self::assertTrue($limiter->hit($scope, 'posting', 1, 60, 'ada')->allowed, 'A new window.'));

        self::assertSame(1, $store->prune($connection->writer(), new DateTimeImmutable('2026-11-14T00:01:30Z')));
        self::assertEquals(1, $connection->reader()->fetchValue('SELECT COUNT(*) FROM shirakaba_rate_limits'));

        $this->expectException(LogicException::class);
        self::inScope(static function (RequestScope $scope) use ($limiter, $database): void {
            RequestDatabase::writer($database, $scope)->transaction(static fn (Writer $db) => $limiter->hit($scope, 'posting', 1, 60, 'bob'));
        });
    }
}
