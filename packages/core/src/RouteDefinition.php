<?php

declare(strict_types=1);

namespace Shirakaba\Core;

use Closure;
use Psr\Http\Server\MiddlewareInterface;

/**
 * A route being declared. Returned by RouteGroup::get() and friends so it can be named and
 * given route middleware; read when the application is built.
 */
final class RouteDefinition
{
    private ?string $name = null;
    private bool $documented = true;

    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    /**
     * @param non-empty-list<string> $methods
     * @internal created by RouteGroup
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly Closure $handler,
    ) {}

    /**
     * Names the route for observers and, later, reverse routing. Names are unique per application.
     */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Leaves the route out of API descriptions such as OpenAPI: routes for other
     * protocols (ActivityPub, WebFinger) or internal endpoints that clients should not call.
     */
    public function undocumented(): static
    {
        $this->documented = false;

        return $this;
    }

    public function isDocumented(): bool
    {
        return $this->documented;
    }

    /**
     * Adds middleware that runs only for this route, inside group middleware.
     */
    public function middleware(MiddlewareInterface ...$middleware): static
    {
        $this->middleware = [...$this->middleware, ...array_values($middleware)];

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return list<MiddlewareInterface>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
