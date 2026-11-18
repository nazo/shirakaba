<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One link of a middleware pipeline. Pipelines are built once from the innermost handler
 * outwards, so no mutable "next middleware" index is shared between requests.
 *
 * @internal
 */
final readonly class MiddlewareNode implements RequestHandlerInterface
{
    private function __construct(
        private MiddlewareInterface $middleware,
        private RequestHandlerInterface $next,
    ) {}

    /**
     * @param list<MiddlewareInterface> $middleware outermost first
     */
    public static function wrap(array $middleware, RequestHandlerInterface $handler): RequestHandlerInterface
    {
        foreach (array_reverse($middleware) as $outer) {
            $handler = new self($outer, $handler);
        }

        return $handler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}
