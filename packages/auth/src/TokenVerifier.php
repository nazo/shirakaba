<?php

declare(strict_types=1);

namespace Shirakaba\Auth;

use Shirakaba\Core\Scope\RequestScope;

/**
 * Turns a bearer token into the principal it identifies (design/09-security.md).
 *
 * The verifier is built once with the application, so anything that belongs to one
 * request, such as the database connection DatabaseMiddleware registers, comes from
 * $scope. An opaque token stored in the database is looked up through it:
 *
 *     public function verify(string $token, RequestScope $scope): ?object
 *     {
 *         $reader = RequestDatabase::reader($scope->get(Database::class), $scope);
 *         ...
 *     }
 */
interface TokenVerifier
{
    /**
     * @param RequestScope $scope the scope of the request that carries the token
     * @return object|null the principal, or null when the token is not valid
     */
    public function verify(string $token, RequestScope $scope): ?object;
}
