<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Internal;

use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;

/**
 * The connections a request leased, one per Database, kept in its scope.
 *
 * @internal
 */
final class ScopedConnections
{
    /** @var array<int, Connection> Database object id => connection */
    private array $connections = [];

    public function get(Database $database): ?Connection
    {
        return $this->connections[spl_object_id($database)] ?? null;
    }

    public function put(Database $database, Connection $connection): void
    {
        $this->connections[spl_object_id($database)] = $connection;
    }
}
