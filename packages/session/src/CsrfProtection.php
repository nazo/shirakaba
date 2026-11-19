<?php

declare(strict_types=1);

namespace Shirakaba\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Http\HttpFailure;

/**
 * Rejects cross-site requests that change state (POST, PUT, PATCH, DELETE).
 *
 * Browsers that send `Sec-Fetch-Site` are judged by it; otherwise `Origin` must be one
 * of the allowed origins. Requests with neither (old browsers, some proxies) must carry
 * the session's token in an `X-CSRF-Token` header or a `_csrf` form field. Place after
 * SessionMiddleware.
 *
 * The token is replaced when the session is regenerated (at login) or invalidated (at
 * logout), so a token seen before a privilege change is useless afterwards. Pages
 * rendered before the change must be reloaded to post again.
 */
final readonly class CsrfProtection implements MiddlewareInterface
{
    public const string FIELD = '_csrf';
    public const string HEADER = 'X-CSRF-Token';
    private const string SESSION_KEY = '_csrf_token';

    /**
     * @param list<string> $allowedOrigins such as ["https://app.example.com"]
     */
    public function __construct(
        private array $allowedOrigins,
    ) {}

    /**
     * The token to put in forms for this session.
     */
    public static function token(Session $session): string
    {
        $token = $session->get(self::SESSION_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }
        $token = Session::newId();
        $session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = Session::of($request);
        $session->onRegenerate(static function (Session $session): void {
            $session->remove(self::SESSION_KEY);
        });

        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $site = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
        if ($site !== '') {
            if (!in_array($site, ['same-origin', 'none'], true)) {
                throw HttpFailure::forbidden('Cross-site requests cannot change state.');
            }

            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== 'null') {
            if (!in_array($origin, $this->allowedOrigins, true)) {
                throw HttpFailure::forbidden('Cross-site requests cannot change state.');
            }

            return $handler->handle($request);
        }

        $expected = $session->get(self::SESSION_KEY);
        $given = $request->getHeaderLine(self::HEADER);
        if ($given === '') {
            $parsed = $request->getParsedBody();
            $field = is_array($parsed) ? $parsed[self::FIELD] ?? '' : '';
            $given = is_string($field) ? $field : '';
        }
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $given)) {
            throw HttpFailure::forbidden('The request is missing a valid CSRF token.');
        }

        return $handler->handle($request);
    }
}
