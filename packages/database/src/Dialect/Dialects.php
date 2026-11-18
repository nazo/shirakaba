<?php

declare(strict_types=1);

namespace Shirakaba\Database\Dialect;

use InvalidArgumentException;
use PDO;

/**
 * Picks the dialect of a connection.
 */
final readonly class Dialects
{
    /**
     * @throws InvalidArgumentException for a driver other than mysql, pgsql or sqlite
     */
    public static function of(PDO $pdo): Dialect
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => new MySql(),
            'pgsql' => new PostgreSql(),
            'sqlite' => new Sqlite(),
            default => throw new InvalidArgumentException(sprintf(
                'No dialect for the PDO driver "%s"; pass one to Database explicitly.',
                is_string($driver) ? $driver : get_debug_type($driver),
            )),
        };
    }
}
