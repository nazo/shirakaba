<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

use InvalidArgumentException;
use Shirakaba\Database\Database;

/**
 * An application's migrations, listed in order, with the database they apply to. The CLI
 * loads a PHP file returning one:
 *
 *     return new Migrations($database, [
 *         '2026_11_11_create_tasks' => new CreateTasks(),
 *         '2026_11_20_add_due_dates' => new AddDueDates(),
 *     ]);
 *
 * Keys are permanent IDs recorded in the database; never rename or reorder applied ones.
 */
final readonly class Migrations
{
    /**
     * @param array<string, Migration> $migrations
     * @param list<class-string> $entities entity classes for `shirakaba-migrate diff` (needs shirakaba/orm)
     * @throws InvalidArgumentException for an invalid ID
     */
    public function __construct(
        public Database $database,
        public array $migrations,
        public array $entities = [],
    ) {
        foreach (array_keys($migrations) as $id) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,191}$/D', $id) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid migration ID "%s".', $id));
            }
        }
    }
}
