<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * The exchange failed in transit: connection refused, TLS failure, timeout, or a response
 * larger than the limit ($tooLarge).
 */
final class NetworkError extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        public readonly bool $timedOut = false,
        public readonly bool $tooLarge = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
