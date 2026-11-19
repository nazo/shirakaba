<?php

declare(strict_types=1);

namespace Shirakaba\Database\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Database\Dialect\MySql;
use Shirakaba\Database\Dialect\PostgreSql;
use Shirakaba\Database\Dialect\Sqlite;
use Shirakaba\Database\Error\QueryTimedOut;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Database\Writer;

#[CoversClass(Reader::class)]
#[CoversClass(Connection::class)]
#[CoversClass(PostgreSql::class)]
#[CoversClass(MySql::class)]
#[CoversClass(Sqlite::class)]
#[CoversClass(QueryTimedOut::class)]
final class StatementTimeoutTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    /**
     * A statement that runs for seconds, or null when the database has no time limit.
     */
    private static function slow(string $dialect): ?string
    {
        return match ($dialect) {
            'pgsql' => 'SELECT pg_sleep(3)',
            'mysql' => 'SELECT COUNT(*) FROM information_schema.columns a, information_schema.columns b, information_schema.columns c',
            default => null,
        };
    }

    private static function limit(Writer $db): string
    {
        $value = match ($db->dialect()->name()) {
            'pgsql' => $db->fetchValue('SHOW statement_timeout'),
            'mysql' => $db->fetchValue('SELECT @@session.max_execution_time'),
            default => 'none',
        };

        return is_scalar($value) ? (string) $value : '?';
    }

    #[DataProvider('databases')]
    public function testCancelsSlowReadsAndLeavesNoLimitBehind(Database $database): void
    {
        $db = $database->connect()->writer();
        $before = self::limit($db);
        self::assertEquals(1, $db->withTimeout(1.0)->fetchValue('SELECT 1'), 'Fast statements run under a limit.');
        $slow = self::slow($db->dialect()->name());
        if ($slow === null) {
            self::assertSame($before, self::limit($db));

            return;
        }

        $started = microtime(true);
        try {
            $db->withTimeout(0.2)->fetchValue($slow);
            self::fail('The statement should have been cancelled.');
        } catch (QueryTimedOut) {
            self::assertLessThan(2.0, microtime(true) - $started);
        }
        self::assertSame($before, self::limit($db), 'The limit applies to one statement only.');

        // Inside a transaction, a cancelled statement rolls the limit back with the work.
        try {
            $db->transaction(static fn (Writer $tx): mixed => $tx->withTimeout(0.2)->fetchValue($slow));
            self::fail('The statement should have been cancelled.');
        } catch (QueryTimedOut) {
        }
        self::assertSame($before, self::limit($db));
    }

    public function testSetsTheLimitOnlyWhenItChanges(): void
    {
        \Shirakaba\Database\Tests\Fixtures\CountingDialect::skipUnlessSupported($this);
        $dialect = new \Shirakaba\Database\Tests\Fixtures\CountingDialect();
        $db = new Database(static fn (): \PDO => \PDO::connect('sqlite::memory:'), $dialect)->connect()->writer();
        $limited = $db->withTimeout(0.5);

        for ($statement = 0; $statement < 6; $statement++) {
            $limited->fetchValue('SELECT 1');
        }
        self::assertSame([500], $dialect->sets, 'Six statements with the same limit share one SET.');

        $db->fetchValue('SELECT 1');
        $db->fetchValue('SELECT 1');
        self::assertSame([500, 0], $dialect->sets, 'The first statement without a limit resets it, once.');

        $db->withTimeout(1.0)->fetchValue('SELECT 1');
        $limited->fetchValue('SELECT 1');
        self::assertSame([500, 0, 1000, 500], $dialect->sets);

        try {
            $db->transaction(static function (Writer $tx): void {
                $tx->withTimeout(0.5)->fetchValue('SELECT 1');
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }
        $limited->fetchValue('SELECT 1');
        self::assertSame([500, 0, 1000, 500, 500], $dialect->sets, 'After a rollback the limit is set again.');
    }

    public function testRejectsNonPositiveLimits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (TestDatabases::available()['sqlite'][0] ?? self::fail('SQLite is always available.'))->connect()->reader()->withTimeout(0);
    }
}
