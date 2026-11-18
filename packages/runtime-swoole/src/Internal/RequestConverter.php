<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Swoole\Internal;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Sapi\ServerRequestBuilder;

/**
 * Turns the parts of a Swoole request into a PSR-7 server request through
 * ServerRequestBuilder, so the rules of the HTTP boundary (Host validation, absolute-form
 * targets, forwarding headers) are the same as under PHP-FPM
 * (design/03-runtimes-and-scopes.md). It takes arrays so it can be tested without Swoole.
 *
 * @internal
 */
final readonly class RequestConverter
{
    /**
     * @param array<array-key, mixed> $server Swoole's $request->server (lowercase keys)
     * @param array<array-key, mixed> $headers Swoole's $request->header (lowercase names)
     * @param array<array-key, mixed> $cookies
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files Swoole's $request->files, laid out like $_FILES
     * @throws InvalidArgumentException when the request cannot be represented (for example an invalid Host header)
     * @throws \RuntimeException when a temporary stream cannot be created
     */
    public static function convert(
        array $server,
        array $headers,
        array $cookies,
        array $query,
        array $post,
        array $files,
        string $body,
        bool $https,
    ): ServerRequestInterface {
        $params = [];
        foreach ($server as $key => $value) {
            if (is_string($key)) {
                $params[strtoupper($key)] = is_int($value) ? (string) $value : $value;
            }
        }
        // Swoole keeps the query string out of request_uri.
        $path = is_string($params['REQUEST_URI'] ?? null) ? $params['REQUEST_URI'] : '/';
        $queryString = is_string($params['QUERY_STRING'] ?? null) ? $params['QUERY_STRING'] : '';
        $params['REQUEST_URI'] = $path . ($queryString !== '' ? '?' . $queryString : '');
        if ($https) {
            $params['HTTPS'] = 'on';
        }
        foreach ($headers as $name => $value) {
            $key = is_string($name) ? self::serverKey($name) : null;
            if ($key !== null && is_string($value)) {
                $params[$key] = $value;
            }
        }

        return ServerRequestBuilder::build($params, $cookies, $query, $post, $files, Stream::fromString($body));
    }

    /**
     * The server variable a header is stored under, or null for names that a SAPI drops
     * because they would be indistinguishable from another header ("X_Forwarded_For"
     * from "X-Forwarded-For").
     */
    private static function serverKey(string $name): ?string
    {
        if ($name === '' || str_contains($name, '_')) {
            return null;
        }
        $key = strtoupper(str_replace('-', '_', $name));

        return in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) ? $key : 'HTTP_' . $key;
    }
}
