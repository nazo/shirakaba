<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Closure;
use Shirakaba\Core\Http\ResponseContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Plans its handler when called instead of at build time (AppBuilder::build(lazy: true)).
 * It plans on every call and keeps nothing, so it holds no state between requests
 * (design/03-runtimes-and-scopes.md); it is meant for PHP-FPM, where each built application serves one request.
 *
 * @internal
 */
final readonly class LazyInvoker implements RequestHandlerInterface
{
    /**
     * @param list<string> $routeParameters
     */
    public function __construct(
        private Closure $handler,
        private string $route,
        private array $routeParameters,
        private ResponseContext $context,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return HandlerInvoker::plan($this->handler, $this->route, $this->routeParameters, $this->context)->invoker->handle($request);
    }
}
