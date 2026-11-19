<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests\Fixtures;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Shirakaba\Http\Message\HttpFactory;

/**
 * A PSR-18 client that records requests and answers with a fixed status.
 */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    public function __construct(
        private readonly int $status = 200,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return new HttpFactory()->createResponse($this->status);
    }
}
