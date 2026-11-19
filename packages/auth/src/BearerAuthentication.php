<?php

declare(strict_types=1);

namespace Shirakaba\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Scope\RequestScope;

/**
 * Verifies `Authorization: Bearer` credentials (RFC 6750, design/09-security.md). A valid token
 * registers an Authentication in the request scope; an invalid one is a 401. A missing
 * token is left to #[Authenticated] parameters, so public and protected routes can share
 * the middleware.
 */
final readonly class BearerAuthentication implements MiddlewareInterface
{
    public function __construct(
        private TokenVerifier $verifier,
        private string $realm = 'api',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        if ($authorization === '') {
            return $handler->handle($request);
        }

        if (preg_match('/^Bearer +([A-Za-z0-9\-._~+\/]+=*)$/D', $authorization, $match) !== 1) {
            throw HttpFailure::unauthorized(self::challenge($this->realm, 'invalid_request'), 'The Authorization header must be "Bearer <token>".');
        }
        $scope = RequestScope::of($request);
        $principal = $this->verifier->verify($match[1], $scope);
        if ($principal === null) {
            throw HttpFailure::unauthorized(self::challenge($this->realm, 'invalid_token'), 'The access token is invalid or expired.');
        }

        $scope->set(new Authentication($principal));

        return $handler->handle($request);
    }

    /**
     * The WWW-Authenticate value for a 401 (RFC 6750, section 3), such as
     * `Bearer realm="api", error="invalid_token"`, for middleware that authenticates
     * bearer tokens in its own way.
     *
     * @param 'invalid_request'|'invalid_token'|'insufficient_scope'|null $error
     */
    public static function challenge(string $realm, ?string $error = null): string
    {
        $challenge = sprintf('Bearer realm="%s"', addcslashes($realm, '"\\'));

        return $error === null ? $challenge : $challenge . sprintf(', error="%s"', $error);
    }
}
