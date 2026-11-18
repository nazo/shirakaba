<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use PDO;
use Shirakaba\Database\Database;
use Shirakaba\Examples\Tasks\Migrations\CreateTasks;
use Shirakaba\Migrations\Migration;
use Shirakaba\Migrations\Migrations;
use Shirakaba\Migrations\Migrator;

final readonly class TaskSchema
{
    /**
     * The example's migrations, in order. `migrations.php` returns them for the
     * shirakaba-migrate CLI.
     *
     * @return array<string, Migration>
     */
    public static function migrations(): array
    {
        return [
            '2026_11_11_000000_create_tasks' => new CreateTasks(),
        ];
    }

    /**
     * A database for the example: SQLite at $file, migrated on first use. An in-memory
     * database is a new database per connection, so each connection is migrated.
     */
    public static function database(string $file): Database
    {
        return new Database(static function () use ($file): PDO {
            $pdo = new PDO('sqlite:' . $file);
            $migrations = new Migrations(new Database(static fn (): PDO => $pdo), self::migrations());
            Migrator::for($migrations)->migrate();

            return $pdo;
        });
    }
}
