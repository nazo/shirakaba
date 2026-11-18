<?php

declare(strict_types=1);

namespace Shirakaba\Database\Testing;

use PDO;
use Shirakaba\Database\Database;

/**
 * The databases a test suite can run against: always an in-memory SQLite database, and
 * MySQL and PostgreSQL when SHIRAKABA_TEST_MYSQL_DSN or SHIRAKABA_TEST_PGSQL_DSN are set (with
 * the matching _USER and _PASSWORD variables). Use it as a data provider so every
 * conformance test runs on each available database.
 */
final readonly class TestDatabases
{
    /**
     * @return array<string, array{Database}>
     */
    public static function available(): array
    {
        $databases = ['sqlite' => [new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))]];
        foreach (['mysql' => 'MYSQL', 'pgsql' => 'PGSQL'] as $name => $prefix) {
            $dsn = getenv('SHIRAKABA_TEST_' . $prefix . '_DSN');
            if (!is_string($dsn) || $dsn === '' || !in_array($name, PDO::getAvailableDrivers(), true)) {
                continue;
            }
            $user = getenv('SHIRAKABA_TEST_' . $prefix . '_USER');
            $password = getenv('SHIRAKABA_TEST_' . $prefix . '_PASSWORD');
            // PDO::connect() returns the driver subclass (Pdo\Pgsql, Pdo\Mysql) that driver-specific features need.
            $databases[$name] = [new Database(static fn (): PDO => PDO::connect(
                $dsn,
                is_string($user) ? $user : null,
                is_string($password) ? $password : null,
            ))];
        }

        return $databases;
    }
}
