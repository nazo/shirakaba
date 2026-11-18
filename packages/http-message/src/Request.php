<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Immutable PSR-7 client-side (outgoing) request.
 */
final class Request extends RequestBase
{
    /**
     * @param array<array-key, mixed> $headers name => string, int or non-empty list of those
     * @throws InvalidArgumentException when the method, URI or a header is invalid
     * @throws \RuntimeException when a string body cannot be stored in a temporary stream
     */
    public function __construct(
        string $method,
        UriInterface|string $uri,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $protocolVersion = '1.1',
    ) {
        parent::__construct($method, $uri, $headers, $body, $protocolVersion);
    }
}
