<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy;

use InvalidArgumentException;
use Shirakaba\Http\Message\Uri;
use Psr\Http\Message\UriInterface;

/**
 * The origin the application is reached at, configured rather than taken from the request,
 * for absolute URLs that must not depend on the Host header: links in e-mails,
 * password-reset and confirmation URLs, OAuth redirect URIs.
 *
 *     $origin = new CanonicalOrigin('https://app.example.com');
 *     $link = $origin->url('/password-reset', ['token' => $token]);
 *
 * Pass it to the handlers that build such links like any other dependency.
 */
final readonly class CanonicalOrigin
{
    private Uri $origin;

    /**
     * @throws InvalidArgumentException when $origin is not "http(s)://host[:port]"
     */
    public function __construct(string $origin)
    {
        try {
            $uri = new Uri($origin);
        } catch (InvalidArgumentException) {
            $uri = null;
        }
        if (
            $uri === null
            || !in_array($uri->getScheme(), ['http', 'https'], true)
            || $uri->getHost() === ''
            || $uri->getUserInfo() !== ''
            || !in_array($uri->getPath(), ['', '/'], true)
            || $uri->getQuery() !== ''
            || $uri->getFragment() !== ''
            || str_contains($origin, '?')
            || str_contains($origin, '#')
        ) {
            throw new InvalidArgumentException(sprintf('Invalid origin "%s"; use "scheme://host[:port]" without a path.', $origin));
        }
        $this->origin = $uri->withPath('');
    }

    public function uri(): UriInterface
    {
        return $this->origin;
    }

    /**
     * An absolute URL on this origin.
     *
     * @param string $path an absolute path such as "/users/42"; it is percent-encoded where needed
     * @param array<string, mixed> $query encoded with http_build_query() (RFC 3986)
     * @throws InvalidArgumentException when $path does not start with "/" or has a query or fragment
     */
    public function url(string $path, array $query = []): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException(sprintf('The path "%s" must start with "/" and pass the query separately.', $path));
        }

        return (string) $this->origin
            ->withPath($path)
            ->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function __toString(): string
    {
        return (string) $this->origin;
    }
}
