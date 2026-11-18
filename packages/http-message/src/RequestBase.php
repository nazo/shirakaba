<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Shared implementation of PSR-7 requests: method, URI and request target.
 *
 * The method is kept exactly as given, since RFC 9110 methods are case-sensitive.
 *
 * @internal Use Request or ServerRequest instead.
 */
abstract class RequestBase extends Message implements RequestInterface
{
    private readonly string $method;
    private readonly UriInterface $uri;
    /**
     * Not readonly: PHPStan does not yet see clone-with assignments, and would infer that
     * a readonly property only ever assigned null in the constructor is always null.
     */
    private ?string $requestTarget = null;

    /**
     * @param array<array-key, mixed> $headers
     * @throws InvalidArgumentException when the method, URI or a header is invalid
     * @throws \RuntimeException when a string body cannot be stored in a temporary stream
     */
    protected function __construct(
        string $method,
        UriInterface|string $uri,
        array $headers,
        StreamInterface|string|null $body,
        string $protocolVersion,
    ) {
        $uri = is_string($uri) ? new Uri($uri) : $uri;
        $host = self::hostOf($uri);
        if ($host !== '' && !self::containsHeader($headers, 'Host')) {
            $headers = ['Host' => $host] + $headers;
        }

        parent::__construct($headers, $body, $protocolVersion);
        $this->method = self::filterMethod($method);
        $this->uri = $uri;
    }

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ($target === '' || $target[0] !== '/') {
            $target = '/' . $target;
        }

        $query = $this->uri->getQuery();

        return $query === '' ? $target : $target . '?' . $query;
    }

    #[\NoDiscard]
    public function withRequestTarget(string $requestTarget): static
    {
        // Only characters that are never valid are checked, not the grammar of the four forms.
        if ($requestTarget === '' || preg_match('/[\x00-\x20\x7F]/', $requestTarget) === 1) {
            throw new InvalidArgumentException('A request target must be non-empty and must not contain whitespace or control characters.');
        }

        return clone($this, ['requestTarget' => $requestTarget]);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    #[\NoDiscard]
    public function withMethod(string $method): static
    {
        $method = self::filterMethod($method);

        return $method === $this->method ? $this : clone($this, ['method' => $method]);
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    #[\NoDiscard]
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $new = $uri === $this->uri ? $this : clone($this, ['uri' => $uri]);
        if ($preserveHost && $this->getHeaderLine('Host') !== '') {
            return $new;
        }

        $host = self::hostOf($uri);

        return $host === '' ? $new : $new->withHostHeader($host);
    }

    private static function hostOf(UriInterface $uri): string
    {
        $host = $uri->getHost();
        if ($host === '') {
            return '';
        }

        $port = $uri->getPort();

        return $port === null ? $host : $host . ':' . $port;
    }

    private static function filterMethod(string $method): string
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $method) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP method "%s".', $method));
        }

        return $method;
    }
}
