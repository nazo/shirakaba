<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Uri\Rfc3986\Uri as Rfc3986Uri;

/**
 * Immutable PSR-7 URI.
 *
 * Parsing is delegated to PHP's RFC 3986 parser. Characters that can never appear in a
 * URI (spaces, control characters, non-ASCII bytes and the like) are percent-encoded
 * first; anything still invalid after that, such as a malformed authority, is rejected
 * rather than repaired. Component setters accept both encoded and unencoded input and
 * percent-encode whatever RFC 3986 does not allow.
 *
 * getPath() collapses multiple leading slashes into one, so that a consumer building a
 * URL from the path alone cannot produce a protocol-relative URL ("//evil.example").
 * __toString() keeps the path as given.
 */
final readonly class Uri implements UriInterface
{
    /**
     * @var array<string, int>
     */
    private const array DEFAULT_PORTS = [
        'http' => 80,
        'https' => 443,
        'ws' => 80,
        'wss' => 443,
    ];

    /** Every character RFC 3986 allows anywhere in a URI reference, besides "%". */
    private const string URI_CHARS = 'A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=';

    /** Characters allowed unencoded in user info, besides percent-encoded octets. */
    private const string USER_INFO_CHARS = 'A-Za-z0-9\-._~!$&\'()*+,;=';

    /** Characters allowed unencoded in a path, besides percent-encoded octets. */
    private const string PATH_CHARS = 'A-Za-z0-9\-._~!$&\'()*+,;=:@\/';

    /** Characters allowed unencoded in a query or fragment, besides percent-encoded octets. */
    private const string QUERY_CHARS = 'A-Za-z0-9\-._~!$&\'()*+,;=:@\/?';

    private string $scheme;
    private string $userInfo;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;
    private string $fragment;

    /**
     * @throws InvalidArgumentException when $uri is not a valid RFC 3986 URI reference
     */
    public function __construct(string $uri = '')
    {
        $parsed = $uri === '' ? null : Rfc3986Uri::parse(self::encodeBracketsOutsideAuthority(self::encode($uri, self::URI_CHARS)));
        if ($uri !== '' && $parsed === null) {
            throw new InvalidArgumentException(sprintf('Unable to parse URI "%s".', $uri));
        }

        $this->scheme = strtolower($parsed?->getRawScheme() ?? '');
        $this->userInfo = $parsed?->getRawUserInfo() ?? '';
        $this->host = strtolower($parsed?->getRawHost() ?? '');
        $this->port = self::filterPort($parsed?->getPort());
        $this->path = $parsed?->getRawPath() ?? '';
        $this->query = $parsed?->getRawQuery() ?? '';
        $this->fragment = $parsed?->getRawFragment() ?? '';
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        $port = $this->getPort();
        if ($port !== null) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        if ($this->port === null || (self::DEFAULT_PORTS[$this->scheme] ?? null) === $this->port) {
            return null;
        }

        return $this->port;
    }

    public function getPath(): string
    {
        return str_starts_with($this->path, '//') ? '/' . ltrim($this->path, '/') : $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    #[\NoDiscard]
    public function withScheme(string $scheme): static
    {
        $scheme = strtolower($scheme);
        if (preg_match('/^(?:[a-z][a-z0-9+\-.]*)?$/D', $scheme) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid URI scheme "%s".', $scheme));
        }

        return $scheme === $this->scheme ? $this : self::unambiguous(clone($this, ['scheme' => $scheme]));
    }

    #[\NoDiscard]
    public function withUserInfo(string $user, ?string $password = null): static
    {
        // PSR-7: an empty user removes the user info, password included.
        $userInfo = $user === '' ? '' : self::encode($user, self::USER_INFO_CHARS);
        if ($userInfo !== '' && $password !== null && $password !== '') {
            $userInfo .= ':' . self::encode($password, self::USER_INFO_CHARS . ':');
        }

        return $userInfo === $this->userInfo ? $this : clone($this, ['userInfo' => $userInfo]);
    }

    #[\NoDiscard]
    public function withHost(string $host): static
    {
        $host = strtolower($host);
        if ($host !== '' && Rfc3986Uri::parse('//' . $host)?->getRawHost() !== $host) {
            throw new InvalidArgumentException(sprintf('Invalid URI host "%s".', $host));
        }

        return $host === $this->host ? $this : self::unambiguous(clone($this, ['host' => $host]));
    }

    #[\NoDiscard]
    public function withPort(?int $port): static
    {
        $port = self::filterPort($port);

        return $port === $this->port ? $this : clone($this, ['port' => $port]);
    }

    #[\NoDiscard]
    public function withPath(string $path): static
    {
        $path = self::encode($path, self::PATH_CHARS);

        return $path === $this->path ? $this : self::unambiguous(clone($this, ['path' => $path]));
    }

    #[\NoDiscard]
    public function withQuery(string $query): static
    {
        $query = self::encode($query, self::QUERY_CHARS);

        return $query === $this->query ? $this : clone($this, ['query' => $query]);
    }

    #[\NoDiscard]
    public function withFragment(string $fragment): static
    {
        $fragment = self::encode($fragment, self::QUERY_CHARS);

        return $fragment === $this->fragment ? $this : clone($this, ['fragment' => $fragment]);
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();
        $path = $this->path;
        if ($authority !== '') {
            $uri .= '//' . $authority;
            if ($path !== '' && $path[0] !== '/') {
                $path = '/' . $path;
            }
        } elseif (str_starts_with($path, '//')) {
            // Without an authority, a leading "//" would be read back as one.
            $path = '/' . ltrim($path, '/');
        }

        $uri .= $path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    /**
     * Rejects a relative reference whose first path segment contains a colon. RFC 3986
     * (path-noscheme) forbids it because "a:b" would be read back with the scheme "a".
     *
     * @template T of self
     * @param T $uri
     * @return T
     */
    private static function unambiguous(self $uri): self
    {
        if ($uri->scheme === '' && $uri->host === '' && preg_match('#^[^/]*:#', $uri->path) === 1) {
            throw new InvalidArgumentException(sprintf(
                'The path "%s" would be read as a scheme in a URI without scheme and host; prefix it with "./".',
                $uri->path,
            ));
        }

        return $uri;
    }

    private static function filterPort(?int $port): ?int
    {
        if ($port !== null && ($port < 0 || $port > 0xFFFF)) {
            throw new InvalidArgumentException(sprintf('Invalid URI port %d; it must be between 0 and 65535.', $port));
        }

        return $port;
    }

    /**
     * Percent-encodes every character outside $allowed, and every "%" that does not start
     * a valid percent-encoded octet, so already encoded input is not encoded twice.
     */
    /**
     * Square brackets are only valid around an IPv6 host, but clients routinely send them
     * in queries ("?tags[]=a"). Encode those after the authority so such requests parse.
     */
    private static function encodeBracketsOutsideAuthority(string $uri): string
    {
        $start = 0;
        $authority = strpos($uri, '//');
        $scheme = strpos($uri, ':');
        $delimiter = strcspn($uri, '/?#');
        // An authority follows "//" at the start or right after "scheme:".
        if ($authority === 0 || $authority !== false && $scheme !== false && $authority === ($scheme + 1) && $scheme < $delimiter) {
            $start = $authority + 2;
            $start += strcspn($uri, '/?#', $start);
        }

        return substr($uri, 0, $start) . str_replace(['[', ']'], ['%5B', '%5D'], substr($uri, $start));
    }

    private static function encode(string $value, string $allowed): string
    {
        return (
            preg_replace_callback(
                '/(?:[^' . $allowed . '%]++|%(?![A-Fa-f0-9]{2}))/',
                static fn (array $match): string => rawurlencode($match[0] ?? ''),
                $value,
            ) ?? throw new InvalidArgumentException('Unable to encode URI component.')
        );
    }
}
