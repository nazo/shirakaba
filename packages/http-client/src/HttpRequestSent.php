<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

use Shirakaba\Core\Observe\Event;

/**
 * Reported to the request's observers for every request the client sends, redirects
 * included. It carries no path, query or headers, which may hold secrets.
 */
final readonly class HttpRequestSent implements Event
{
    public function __construct(
        public string $method,
        public string $scheme,
        public string $host,
        public int $port,
        /** 0 when no response arrived. */
        public int $status,
        public float $seconds,
        /** Why it failed, such as "timeout"; empty on success. */
        public string $error = '',
    ) {}

    public function name(): string
    {
        return 'http.client.request';
    }

    public function duration(): float
    {
        return $this->seconds;
    }

    /**
     * Attribute names follow the OpenTelemetry HTTP client semantic conventions.
     *
     * @return array<string, string|int>
     */
    public function attributes(): array
    {
        return [
            'http.request.method' => $this->method,
            'url.scheme' => $this->scheme,
            'server.address' => $this->host,
            'server.port' => $this->port,
            ...($this->status === 0 ? [] : ['http.response.status_code' => $this->status]),
            ...($this->error === '' ? [] : ['error.type' => $this->error]),
        ];
    }
}
