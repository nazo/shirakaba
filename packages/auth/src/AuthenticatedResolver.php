<?php

declare(strict_types=1);

namespace Shirakaba\Auth;

use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Core\Http\HttpFailure;

/**
 * Hands a handler the authenticated principal. A plain serialisable object, so build plans can be cached (design/04-routing-and-handlers.md).
 *
 * @internal
 */
final readonly class AuthenticatedResolver implements ArgumentResolver
{
    /**
     * @param class-string $class
     */
    public function __construct(
        private string $class,
        private bool $optional,
        private string $realm,
    ) {}

    public function resolve(Exchange $exchange): ?object
    {
        $principal = $exchange->scope->find(Authentication::class)?->principal;
        if ($principal instanceof $this->class) {
            return $principal;
        }
        if ($principal === null && $this->optional) {
            return null;
        }

        throw $principal === null
            ? HttpFailure::unauthorized(BearerAuthentication::challenge($this->realm), 'Authentication is required.')
            : HttpFailure::forbidden('This principal cannot use this operation.');
    }
}
