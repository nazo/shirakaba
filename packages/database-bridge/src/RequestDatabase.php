<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge;

use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\Internal\ScopedConnections;
use Shirakaba\DatabaseBridge\Internal\ScopeEvents;

/**
 * A request's database access (design/08-database.md): the connection is leased on first use, its events
 * go to the request's observers, and it is released, rolled back and reset when the request
 * scope closes (design/03-runtimes-and-scopes.md and design/08-database.md). `#[Db]` parameters use it; code that holds the scope
 * calls it directly:
 *
 *     $writer = RequestDatabase::writer($database, $scope);
 */
final readonly class RequestDatabase
{
    /**
     * The request's connection to $database, opened on first use and released with the scope.
     *
     * @throws DatabaseError when connecting fails
     * @phpstan-impure io.db
     */
    public static function connection(Database $database, RequestScope $scope): Connection
    {
        $connections = $scope->find(ScopedConnections::class);
        if ($connections === null) {
            $connections = new ScopedConnections();
            $scope->set($connections);
        }
        $connection = $connections->get($database);
        if ($connection !== null) {
            return $connection;
        }
        $lease = $database->lease(new ScopeEvents($scope));
        $connections->put($database, $lease->connection);
        $scope->defer($lease->release(...));

        return $lease->connection;
    }

    /**
     * @throws DatabaseError when connecting fails
     */
    public static function reader(Database $database, RequestScope $scope): Reader
    {
        return self::connection($database, $scope)->reader();
    }

    /**
     * A reader on the replica when there is one and this request has not taken a Writer on
     * $database; otherwise a reader on $database, so a request reads its own writes (design/08-database.md).
     * Replicas lag: data written by an earlier request may not be there yet.
     *
     * @throws DatabaseError when connecting fails
     */
    public static function replicaReader(Database $database, RequestScope $scope): Reader
    {
        if ($database->replica === null || $scope->find(ScopedConnections::class)?->get($database)?->hasWriter() === true) {
            return self::reader($database, $scope);
        }

        return self::reader($database->replica, $scope);
    }

    /**
     * @throws DatabaseError when connecting fails
     */
    public static function writer(Database $database, RequestScope $scope): Writer
    {
        return self::connection($database, $scope)->writer();
    }

    /**
     * Sets a session setting on the request's connection to $database for the rest of the
     * request, such as the tenant a PostgreSQL row-level security policy reads (design/08-database.md):
     *
     *     RequestDatabase::setting($database, $scope, 'app.organization_id', $organizationId);
     *
     * Call it from middleware right after authentication, before any statement runs.
     *
     * @throws DatabaseError when connecting fails
     */
    public static function setting(Database $database, RequestScope $scope, string $name, ?string $value): void
    {
        self::connection($database, $scope)->setSetting($name, $value);
    }
}
