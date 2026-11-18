<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\RoadRunner\Internal;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Sapi\ServerRequestBuilder;
use Spiral\RoadRunner\Http\Request;

/**
 * Turns a RoadRunner request into a PSR-7 server request through ServerRequestBuilder, so
 * the rules of the HTTP boundary (Host validation, absolute-form targets, forwarding headers)
 * are the same as under PHP-FPM (design/03-runtimes-and-scopes.md).
 *
 * @internal
 */
final readonly class RequestConverter
{
    /**
     * @throws InvalidArgumentException when the request cannot be represented (for example an invalid Host header)
     * @throws \RuntimeException when a temporary stream cannot be created
     */
    public static function convert(Request $request): ServerRequestInterface
    {
        $uri = parse_url($request->uri);
        if ($uri === false) {
            throw new InvalidArgumentException('Invalid request URI.');
        }
        $query = $uri['query'] ?? '';

        $server = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => ($uri['path'] ?? '/') . ($query !== '' ? '?' . $query : ''),
            'QUERY_STRING' => $query,
            'SERVER_PROTOCOL' => $request->protocol,
            'REMOTE_ADDR' => $request->remoteAddr,
        ];
        if (strtolower($uri['scheme'] ?? '') === 'https') {
            $server['HTTPS'] = 'on';
        }
        if (isset($uri['host'])) {
            // Used only when the request has no Host header.
            $server['SERVER_NAME'] = $uri['host'];
            if (isset($uri['port'])) {
                $server['SERVER_PORT'] = (string) $uri['port'];
            }
        }
        foreach ($request->headers as $name => $values) {
            $key = self::serverKey((string) $name);
            if ($key !== null) {
                $server[$key] = implode(strcasecmp((string) $name, 'Cookie') === 0 ? '; ' : ', ', $values);
            }
        }

        try {
            $post = $request->getParsedBody() ?? [];
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid form data.', previous: $e);
        }

        return ServerRequestBuilder::build(
            $server,
            $request->cookies,
            $request->query,
            $post,
            self::files($request->uploads),
            // RoadRunner replaces a form body it parsed with the parsed fields, so the raw body is gone.
            Stream::fromString($request->parsed ? '' : $request->body),
        );
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

    /**
     * RoadRunner's upload tree ({name, mime, size, error, tmpName} leaves) in the layout of
     * $_FILES that ServerRequestBuilder reads.
     *
     * @param array<array-key, mixed> $uploads
     * @return array<array-key, mixed>
     */
    private static function files(array $uploads): array
    {
        $files = [];
        foreach ($uploads as $field => $upload) {
            if (!is_array($upload)) {
                continue;
            }
            $files[$field] = array_key_exists('tmpName', $upload)
                ? [
                    'tmp_name' => $upload['tmpName'],
                    'size' => $upload['size'] ?? null,
                    'error' => $upload['error'] ?? null,
                    'name' => $upload['name'] ?? null,
                    'type' => $upload['mime'] ?? null,
                ]
                : self::files($upload);
        }

        return $files;
    }
}
