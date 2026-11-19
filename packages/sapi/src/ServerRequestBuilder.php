<?php

declare(strict_types=1);

namespace Shirakaba\Sapi;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Shirakaba\Http\Message\ServerRequest;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Http\Message\UploadedFile;
use Shirakaba\Http\Message\Uri;

/**
 * Builds a PSR-7 server request from the values a classic SAPI provides.
 *
 * fromSuperglobals() is the only place in Shirakaba that reads superglobals; build() takes
 * them as arguments so the conversion can be tested and reused.
 *
 * Forwarding headers (X-Forwarded-*, Forwarded) are not trusted here: which proxies to
 * trust is a deployment decision for a separate middleware.
 */
final readonly class ServerRequestBuilder
{
    /**
     * @throws InvalidArgumentException when the request is malformed (for example an invalid Host header)
     * @throws \RuntimeException when php://input cannot be opened
     * @phpstan-impure global.read, io
     */
    public static function fromSuperglobals(): ServerRequestInterface
    {
        return self::build($_SERVER, $_COOKIE, $_GET, $_POST, $_FILES, Stream::fromFile('php://input', 'r'));
    }

    /**
     * @param array<array-key, mixed> $server $_SERVER
     * @param array<array-key, mixed> $cookies $_COOKIE
     * @param array<array-key, mixed> $query $_GET
     * @param array<array-key, mixed> $post $_POST
     * @param array<array-key, mixed> $files $_FILES
     * @throws InvalidArgumentException when the request is malformed (for example an invalid Host header)
     * @throws \RuntimeException when a temporary stream cannot be created
     */
    public static function build(
        array $server,
        array $cookies,
        array $query,
        array $post,
        array $files,
        StreamInterface $body,
    ): ServerRequestInterface {
        $method = self::string($server, 'REQUEST_METHOD') ?? 'GET';
        $headers = self::headers($server);
        $contentType = strtolower(trim(explode(';', $headers['Content-Type'] ?? '', 2)[0]));
        $parsedBody = $method === 'POST' && in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)
            ? $post
            : null;

        return new ServerRequest(
            $method,
            self::uri($server, $headers['Host'] ?? null),
            $headers,
            $body,
            self::protocolVersion($server),
            serverParams: $server,
            cookieParams: $cookies,
            queryParams: $query,
            uploadedFiles: self::uploadedFiles($files),
            parsedBody: $parsedBody,
        );
    }

    /**
     * The header a server variable carries ("HTTP_ACCEPT" is "ACCEPT"), or null.
     */
    private static function headerName(int|string $key): ?string
    {
        if (!is_string($key)) {
            return null;
        }
        if (str_starts_with($key, 'HTTP_')) {
            return substr($key, 5);
        }

        return in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) ? $key : null;
    }

    /**
     * @param array<array-key, mixed> $server
     * @return array<string, string>
     */
    private static function headers(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            $name = self::headerName($key);
            if ($name !== null && $name !== '' && is_string($value)) {
                $headers[ucwords(strtolower(str_replace('_', '-', $name)), '-')] = $value;
            }
        }

        // Apache hides Authorization unless it is rewritten into REDIRECT_HTTP_AUTHORIZATION.
        $redirected = self::string($server, 'REDIRECT_HTTP_AUTHORIZATION');
        if (!isset($headers['Authorization']) && $redirected !== null) {
            $headers['Authorization'] = $redirected;
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function uri(array $server, ?string $hostHeader): Uri
    {
        $https = strtolower(self::string($server, 'HTTPS') ?? '');
        $scheme = $https !== '' && $https !== 'off' ? 'https' : 'http';

        $uri = new Uri()->withScheme($scheme);

        $host = $hostHeader ?? self::string($server, 'SERVER_NAME');
        if ($host !== null && $host !== '') {
            $port = null;
            // "host:port", including "[::1]:8080".
            if (preg_match('/^(\[[^\]]+\]|[^:]+)(?::(\d+))?$/D', $host, $match) !== 1) {
                throw new InvalidArgumentException('Invalid Host header.');
            }
            if (isset($match[2])) {
                $port = (int) $match[2];
            } elseif ($hostHeader === null) {
                $serverPort = self::string($server, 'SERVER_PORT');
                $port = $serverPort !== null && ctype_digit($serverPort) ? (int) $serverPort : null;
            }
            $uri = $uri->withHost($match[1])->withPort($port);
        }

        $target = self::string($server, 'REQUEST_URI') ?? '/';
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $target) === 1) {
            // Absolute-form request target (proxies): keep only path and query.
            $absolute = new Uri($target);
            $target = $absolute->getPath() . ($absolute->getQuery() !== '' ? '?' . $absolute->getQuery() : '');
        }
        $parsed = new Uri(str_starts_with($target, '/') ? $target : '/' . $target);

        return $uri->withPath($parsed->getPath() === '' ? '/' : $parsed->getPath())->withQuery($parsed->getQuery());
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function protocolVersion(array $server): string
    {
        $protocol = self::string($server, 'SERVER_PROTOCOL') ?? 'HTTP/1.1';

        return preg_match('#^HTTP/(\d(?:\.\d)?)$#D', $protocol, $match) === 1 ? $match[1] : '1.1';
    }

    /**
     * Normalises $_FILES, including PHP's "files[a][b]" layout where each attribute is a
     * separate nested array, into a tree of UploadedFile.
     *
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private static function uploadedFiles(array $files): array
    {
        $normalised = [];
        foreach ($files as $field => $spec) {
            if (is_array($spec) && array_key_exists('tmp_name', $spec)) {
                $normalised[$field] = self::fileTree(
                    $spec['tmp_name'],
                    $spec['size'] ?? null,
                    $spec['error'] ?? null,
                    $spec['name'] ?? null,
                    $spec['type'] ?? null,
                );
            } elseif (is_array($spec)) {
                $normalised[$field] = self::uploadedFiles($spec);
            }
        }

        return $normalised;
    }

    private static function fileTree(mixed $tmpName, mixed $size, mixed $error, mixed $name, mixed $type): mixed
    {
        if (is_array($tmpName)) {
            $tree = [];
            foreach ($tmpName as $key => $nestedTmpName) {
                $tree[$key] = self::fileTree(
                    $nestedTmpName,
                    is_array($size) ? $size[$key] ?? null : null,
                    is_array($error) ? $error[$key] ?? null : null,
                    is_array($name) ? $name[$key] ?? null : null,
                    is_array($type) ? $type[$key] ?? null : null,
                );
            }

            return $tree;
        }

        $error = is_int($error) ? $error : UPLOAD_ERR_NO_FILE;
        $path = is_string($tmpName) && $tmpName !== '' ? $tmpName : null;

        return new UploadedFile(
            // A failed upload has no file; an empty stream keeps the object valid.
            $path ?? Stream::fromString(),
            is_int($size) ? $size : null,
            $path === null && $error === UPLOAD_ERR_OK ? UPLOAD_ERR_NO_FILE : $error,
            is_string($name) ? $name : null,
            is_string($type) ? $type : null,
        );
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function string(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
