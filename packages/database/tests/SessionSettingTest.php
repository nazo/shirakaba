<?php

declare(strict_types=1);

namespace Shirakaba\Database\Tests;

use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Database\Dialect\PostgreSql;
use Shirakaba\Database\Tests\Fixtures\CountingDialect;
use Shirakaba\Database\Writer;

/**
 * Session settings for row-level security (design/08-database.md).
 */
#[CoversClass(Connection::class)]
#[CoversClass(PostgreSql::class)]
final class SessionSettingTest extends TestCase
{
    public function testAppliesASettingOnlyWhenItChangesAndAgainAfterARollback(): void
    {
        \Shirakaba\Database\Tests\Fixtures\CountingDialect::skipUnlessSupported($this);
        $dialect = new CountingDialect();
        $lease = new Database(static fn (): PDO => PDO::connect('sqlite::memory:'), $dialect)->lease();
        $connection = $lease->connection;
        $db = $connection->writer();

        $connection->setSetting('app.organization_id', '7');
        $db->fetchValue('SELECT 1');
        $db->fetchValue('SELECT 2');
        self::assertSame(['app.organization_id=7'], $dialect->settings, 'One setting serves every following statement.');

        $connection->setSetting('app.organization_id', null);
        $db->fetchValue('SELECT 3');
        self::assertSame(['app.organization_id=7', 'app.organization_id='], $dialect->settings);

        try {
            $db->transaction(static function (Writer $db): never {
                $db->fetchValue('SELECT 4');
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // expected
        }
        $db->fetchValue('SELECT 5');
        self::assertSame(['app.organization_id=7', 'app.organization_id=', 'app.organization_id='], $dialect->settings, 'A rollback may have undone the setting, so it is set again.');
        $lease->release();
    }

    public function testRefusesBuiltInParametersAndDatabasesWithoutSettings(): void
    {
        $connection = new Database(static fn (): PDO => PDO::connect('sqlite::memory:'), new CountingDialect())->connect();
        foreach (['search_path', 'App.Tenant', 'app.tenant; DROP TABLE x'] as $name) {
            try {
                $connection->setSetting($name, 'x');
                self::fail(sprintf('"%s" must be refused.', $name));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sqlite has no session settings');
        new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect()->setSetting('app.tenant', '1');
    }

    /**
     * The policy filters raw queries as the application's role, and a reused connection starts
     * the next lease without the setting.
     */
    public function testRowLevelSecurityOnPostgreSql(): void
    {
        $dsn = getenv('SHIRAKABA_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '' || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Set SHIRAKABA_TEST_PGSQL_DSN to run against PostgreSQL.');
        }
        $user = getenv('SHIRAKABA_TEST_PGSQL_USER');
        $password = getenv('SHIRAKABA_TEST_PGSQL_PASSWORD');
        $database = new Database(static fn (): PDO => PDO::connect($dsn, is_string($user) ? $user : null, is_string($password) ? $password : null), reuse: true);
        $setup = $database->connect()->writer();
        $setup->execute('DROP TABLE IF EXISTS rls_cards');
        $setup->execute("DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'shirakaba_tenant_app') THEN CREATE ROLE shirakaba_tenant_app NOLOGIN; END IF; END $$");
        $setup->execute('CREATE TABLE rls_cards (organization_id int NOT NULL, body text NOT NULL)');
        $setup->execute("INSERT INTO rls_cards VALUES (1, 'ours'), (2, 'theirs')");
        $setup->execute('ALTER TABLE rls_cards ENABLE ROW LEVEL SECURITY');
        $setup->execute("CREATE POLICY rls_cards_tenant ON rls_cards USING (organization_id = NULLIF(current_setting('app.organization_id', true), '')::int)");
        $setup->execute('GRANT SELECT ON rls_cards TO shirakaba_tenant_app');

        $lease = $database->lease();
        $db = $lease->connection->writer();
        // The application's role: neither the owner nor a superuser, whom RLS does not bind.
        $db->execute('SET ROLE shirakaba_tenant_app');
        $lease->connection->setSetting('app.organization_id', '1');
        self::assertSame([['body' => 'ours']], $db->fetchAll('SELECT body FROM rls_cards ORDER BY body'));
        $lease->release();

        $next = $database->lease();
        self::assertSame($lease->connection->pdo, $next->connection->pdo, 'The connection was reused.');
        self::assertSame('', $next->connection->reader()->fetchValue("SELECT COALESCE(current_setting('app.organization_id', true), '')"), 'The setting did not outlive its lease.');
        $next->connection->writer()->execute('SET ROLE shirakaba_tenant_app');
        self::assertSame([], $next->connection->reader()->fetchAll('SELECT body FROM rls_cards'), 'Without the setting no row is visible.');
        $next->release();
        $database->connect()->writer()->execute('DROP TABLE rls_cards');
    }
}
