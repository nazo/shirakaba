<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge;

use LogicException;
use Shirakaba\Database\Database;

/**
 * The databases of an application by name, for `#[Db('analytics')]` (design/08-database.md).
 * DatabaseMiddleware places it in every request scope.
 */
final readonly class Databases
{
    /**
     * @param array<string, Database> $named
     */
    public function __construct(
        public Database $default,
        public array $named = [],
    ) {}

    /**
     * @throws LogicException when no database has that name
     */
    public function get(?string $name = null): Database
    {
        if ($name === null) {
            return $this->default;
        }

        return (
            $this->named[$name] ?? throw new LogicException(sprintf(
                'No database is named "%s"; name it in DatabaseMiddleware: new DatabaseMiddleware($default, [%s => $database]).',
                $name,
                var_export($name, true),
            ))
        );
    }
}
