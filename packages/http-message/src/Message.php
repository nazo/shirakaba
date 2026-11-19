<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Shared implementation of PSR-7 messages: protocol version, headers and body.
 *
 * Header names must be RFC 9110 tokens. Header values may contain only visible
 * characters, spaces and tabs, which rules out header injection through CR, LF or NUL.
 * Surrounding whitespace is trimmed from values.
 *
 * @internal Extend Request, ServerRequest or Response instead.
 */
abstract class Message implements MessageInterface
{
    /**
     * Header values keyed by the name as first given. PHP turns numeric names into int keys.
     *
     * @var array<array-key, list<string>>
     */
    private readonly array $headers;

    /** @var array<string, array-key> lower-cased name => key in $headers */
    private readonly array $headerNames;

    private readonly string $protocolVersion;

    /** Created lazily so messages without a body do not open a stream. */
    private ?StreamInterface $body;

    /**
     * @param array<array-key, mixed> $headers name => string, int or non-empty list of those
     * @throws InvalidArgumentException when a header name, header value or protocol version is invalid
     * @throws \RuntimeException when a string body cannot be stored in a temporary stream
     */
    protected function __construct(array $headers, StreamInterface|string|null $body, string $protocolVersion)
    {
        $values = [];
        $names = [];
        foreach ($headers as $name => $value) {
            $name = self::filterName((string) $name);
            $lower = strtolower($name);
            $key = $names[$lower] ?? $name;
            $names[$lower] = $key;
            $values[$key] = [...($values[$key] ?? []), ...self::filterValue($value)];
        }

        $this->headers = $values;
        $this->headerNames = $names;
        $this->protocolVersion = self::filterProtocolVersion($protocolVersion);
        $this->body = is_string($body) ? ($body === '' ? null : Stream::fromString($body)) : $body;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[\NoDiscard]
    public function withProtocolVersion(string $version): static
    {
        $version = self::filterProtocolVersion($version);

        return $version === $this->protocolVersion ? $this : clone($this, ['protocolVersion' => $version]);
    }

    /**
     * @return array<array-key, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        $key = $this->headerNames[strtolower($name)] ?? null;

        return $key === null ? [] : $this->headers[$key] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * @param string|int|array<string|int> $value an empty array is rejected at runtime
     */
    #[\NoDiscard]
    public function withHeader(string $name, $value): static
    {
        $name = self::filterName($name);
        $values = self::filterValue($value);
        $lower = strtolower($name);

        $headers = $this->headers;
        $existing = $this->headerNames[$lower] ?? null;
        if ($existing !== null) {
            unset($headers[$existing]);
        }
        $headers[$name] = $values;
        $names = $this->headerNames;
        $names[$lower] = $name;

        return clone($this, ['headers' => $headers, 'headerNames' => $names]);
    }

    /**
     * @param string|int|array<string|int> $value an empty array is rejected at runtime
     */
    #[\NoDiscard]
    public function withAddedHeader(string $name, $value): static
    {
        $name = self::filterName($name);
        $values = self::filterValue($value);
        $existing = $this->headerNames[strtolower($name)] ?? null;
        if ($existing === null) {
            return $this->withHeader($name, $values);
        }

        $headers = $this->headers;
        $headers[$existing] = [...($headers[$existing] ?? []), ...$values];

        return clone($this, ['headers' => $headers]);
    }

    #[\NoDiscard]
    public function withoutHeader(string $name): static
    {
        $lower = strtolower($name);
        $existing = $this->headerNames[$lower] ?? null;
        if ($existing === null) {
            return $this;
        }

        $headers = $this->headers;
        $names = $this->headerNames;
        unset($headers[$existing], $names[$lower]);

        return clone($this, ['headers' => $headers, 'headerNames' => $names]);
    }

    public function getBody(): StreamInterface
    {
        return $this->body ??= Stream::fromString();
    }

    #[\NoDiscard]
    public function withBody(StreamInterface $body): static
    {
        return $body === $this->body ? $this : clone($this, ['body' => $body]);
    }

    /**
     * Sets the Host header and moves it to the front, as RFC 9110 recommends.
     */
    #[\NoDiscard]
    protected function withHostHeader(string $host): static
    {
        $headers = $this->headers;
        $existing = $this->headerNames['host'] ?? null;
        if ($existing !== null) {
            unset($headers[$existing]);
        }

        $names = $this->headerNames;
        $names['host'] = 'Host';

        // Array union, not spread: spread would renumber numeric header names.
        return clone($this, ['headers' => ['Host' => self::filterValue($host)] + $headers, 'headerNames' => $names]);
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    protected static function containsHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function filterName(string $name): string
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid header name "%s".', $name));
        }

        return $name;
    }

    /**
     * @return non-empty-list<string>
     */
    private static function filterValue(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        if ($values === []) {
            throw new InvalidArgumentException('A header value must not be an empty array.');
        }

        $filtered = [];
        foreach ($values as $item) {
            if (!is_string($item) && !is_int($item)) {
                throw new InvalidArgumentException(sprintf('A header value must be a string or an integer, %s given.', get_debug_type($item)));
            }
            $item = (string) $item;
            if (preg_match('/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D', $item) !== 1) {
                throw new InvalidArgumentException('A header value must not contain control characters such as CR, LF or NUL.');
            }
            $filtered[] = trim($item, " \t");
        }

        return $filtered;
    }

    private static function filterProtocolVersion(string $version): string
    {
        if (preg_match('/^\d(?:\.\d)?$/D', $version) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP protocol version "%s".', $version));
        }

        return $version;
    }
}
