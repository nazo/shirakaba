<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The client would not send the request: a scheme other than HTTP(S), a host that does not
 * resolve or resolves to a refused address, or too many redirects. Nothing was sent to the
 * refused target.
 */
final class RequestRefused extends RuntimeException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
