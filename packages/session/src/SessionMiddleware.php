<?php

declare(strict_types=1);

namespace Shirakaba\Session;

use Shirakaba\Core\Scope\RequestScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Loads the session for each request into the RequestScope and saves it afterwards.
 * Does not use PHP's session_* functions, which rely on global state (design/03-runtimes-and-scopes.md).
 *
 * A new, untouched session sets no cookie. An unknown or malformed session ID is replaced
 * by a fresh one, never adopted, which prevents session fixation.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SessionStore $store,
        private string $cookieName = '__Host-session',
        /** Seconds a session lives without being saved again. */
        private int $lifetime = 7200,
        private bool $secureCookie = true,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = RequestScope::of($request);
        $id = $this->cookie($request);
        $data = $id === null ? null : $this->store->load($scope, $id);
        $session = $data === null ? new Session(Session::newId(), [], true) : new Session($id ?? Session::newId(), $data, false);
        $scope->set($session);

        $response = $handler->handle($request);

        if ($session->isInvalidated()) {
            if (!$session->isNew()) {
                $this->store->delete($scope, $session->id());
            }

            return SetCookie::delete($this->cookieName)->addTo($response);
        }
        if ($session->isRegenerated() && !$session->isNew()) {
            $this->store->delete($scope, $session->id());
            $session->assignId(Session::newId());
        }
        if (!$session->isModified() && !$session->isNew()) {
            // Keep the session alive without rewriting the cookie.
            $this->store->save($scope, $session->id(), $session->data(), $this->lifetime);

            return $response;
        }
        if ($session->isNew() && !$session->isModified()) {
            return $response;
        }

        $this->store->save($scope, $session->id(), $session->data(), $this->lifetime);

        return new SetCookie($this->cookieName, $session->id(), $this->lifetime, secure: $this->secureCookie)->addTo($response);
    }

    private function cookie(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[$this->cookieName] ?? null;
        if (!is_string($value)) {
            foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
                $separator = strpos($pair, '=');
                if ($separator !== false && trim(substr($pair, 0, $separator)) === $this->cookieName) {
                    $value = trim(substr($pair, $separator + 1));
                }
            }
        }

        return is_string($value) && Session::isValidId($value) ? $value : null;
    }
}
