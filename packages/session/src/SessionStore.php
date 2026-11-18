<?php

declare(strict_types=1);

namespace Shirakaba\Session;

use Shirakaba\Core\Scope\RequestScope;

/**
 * Where session data lives between requests. Implementations must be safe to share
 * between concurrent requests (design/03-runtimes-and-scopes.md). Each call receives the request's scope, so a store
 * can use request-scoped resources such as the request's database connection.
 */
interface SessionStore
{
    /**
     * @return array<string, mixed>|null null when the session does not exist or expired
     */
    public function load(RequestScope $scope, string $id): ?array;

    /**
     * @param array<string, mixed> $data JSON-encodable
     * @param int $lifetime seconds the session lives without being saved again
     */
    public function save(RequestScope $scope, string $id, array $data, int $lifetime): void;

    public function delete(RequestScope $scope, string $id): void;
}
