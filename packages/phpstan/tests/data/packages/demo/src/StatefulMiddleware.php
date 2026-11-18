<?php

declare(strict_types=1);

namespace Demo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class StatefulMiddleware implements MiddlewareInterface
{
    private ?ServerRequestInterface $lastRequest = null;

    /** @var list<string> */
    private array $seen = [];

    private int $count;

    public function __construct()
    {
        $this->count = 0;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->lastRequest = $request;
        $this->seen[] = $request->getMethod();
        $this->count += 1;
        $local = new \stdClass();
        $local->value = $request;

        return $handler->handle($request);
    }
}
