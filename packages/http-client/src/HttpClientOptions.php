<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

use Closure;
use Psr\Http\Message\RequestInterface;

/**
 * Limits and hooks of an HttpClient (design/09-security.md). The defaults suit calls to servers the
 * application does not control.
 */
final readonly class HttpClientOptions
{
    /**
     * @param (Closure(string): bool)|null $allowAddress whether an IP address may be connected
     *        to; by default only globally routable addresses (no loopback, private, link-local,
     *        metadata or other special ranges)
     * @param (Closure(RequestInterface): RequestInterface)|null $prepare applied to every request
     *        sent, including each redirect, after the client's own headers: the place to sign
     *        requests (HTTP Signatures) or add credentials for the final target only
     */
    public function __construct(
        /** Seconds for the whole exchange, redirects included. */
        public float $timeout = 10.0,
        /** Seconds to establish each connection. */
        public float $connectTimeout = 5.0,
        /** Bytes of response body accepted before the transfer is aborted. */
        public int $maxResponseBytes = 10_000_000,
        /** Redirects followed; 0 returns 3xx responses as they are. */
        public int $maxRedirects = 5,
        public ?Closure $allowAddress = null,
        public ?Closure $prepare = null,
        public string $userAgent = 'Shirakaba',
    ) {}
}
