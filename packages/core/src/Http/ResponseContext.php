<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The application's PSR-17 factories, handed to response values, and the route's default
 * status: its declared success status when it declares exactly one (design/06-json-and-openapi.md).
 */
final readonly class ResponseContext
{
    public function __construct(
        public ResponseFactoryInterface $responses,
        public StreamFactoryInterface $streams,
        public ?int $defaultStatus = null,
    ) {}

    /**
     * The status a response value should use when it was not given one.
     */
    public function status(?int $given, int $fallback = 200): int
    {
        return $given ?? $this->defaultStatus ?? $fallback;
    }
}
