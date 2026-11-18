<?php

declare(strict_types=1);

namespace Shirakaba\Core;

use Closure;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Declares routes under a common path prefix and middleware (design/04-routing-and-handlers.md).
 *
 * Handlers are any callable; first-class callable syntax (`show_user(...)`) is the
 * recommended style. Within a group, the path "" means the prefix itself.
 */
final class RouteGroup
{
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    /** @var list<RouteDefinition|RouteGroup> */
    private array $children = [];

    /**
     * @throws InvalidApplication when the prefix is not "" or a path without a trailing slash
     * @internal create groups with AppBuilder::group() or RouteGroup::group()
     */
    public function __construct(
        private readonly string $prefix = '',
    ) {
        if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_ends_with($prefix, '/'))) {
            throw new InvalidApplication(sprintf('Group prefix "%s" must start with "/" and must not end with "/".', $prefix));
        }
    }

    /**
     * Adds middleware that runs for every route of this group and its subgroups, outer groups first.
     */
    public function middleware(MiddlewareInterface ...$middleware): static
    {
        $this->middleware = [...$this->middleware, ...array_values($middleware)];

        return $this;
    }

    public function get(string $path, callable $handler): RouteDefinition
    {
        return $this->route(['GET'], $path, $handler);
    }

    public function post(string $path, callable $handler): RouteDefinition
    {
        return $this->route(['POST'], $path, $handler);
    }

    public function put(string $path, callable $handler): RouteDefinition
    {
        return $this->route(['PUT'], $path, $handler);
    }

    public function patch(string $path, callable $handler): RouteDefinition
    {
        return $this->route(['PATCH'], $path, $handler);
    }

    public function delete(string $path, callable $handler): RouteDefinition
    {
        return $this->route(['DELETE'], $path, $handler);
    }

    public function head(string $path, callable $handler): RouteDefinition
    {
        return $this->route(['HEAD'], $path, $handler);
    }

    public function options(string $path, callable $handler): RouteDefinition
    {
        return $this->route(['OPTIONS'], $path, $handler);
    }

    /**
     * @param non-empty-list<string> $methods case-sensitive method tokens
     * @throws InvalidApplication when a method is not a valid token
     */
    public function route(array $methods, string $path, callable $handler): RouteDefinition
    {
        foreach ($methods as $method) {
            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $method) !== 1) {
                throw new InvalidApplication(sprintf('Invalid HTTP method "%s".', $method));
            }
        }

        $route = new RouteDefinition(array_values(array_unique($methods)), $path, Closure::fromCallable($handler));
        $this->children[] = $route;

        return $route;
    }

    /**
     * @param Closure(RouteGroup): void $define declares the group's routes
     */
    public function group(string $prefix, Closure $define): self
    {
        $group = new self($prefix);
        $define($group);
        $this->children[] = $group;

        return $group;
    }

    /**
     * @param list<MiddlewareInterface> $outerMiddleware
     * @return list<array{route: RouteDefinition, path: string, middleware: list<MiddlewareInterface>}>
     * @internal used by AppBuilder::build()
     */
    public function flatten(string $outerPrefix = '', array $outerMiddleware = []): array
    {
        $prefix = $outerPrefix . $this->prefix;
        $middleware = [...$outerMiddleware, ...$this->middleware];
        $routes = [];
        foreach ($this->children as $child) {
            if ($child instanceof self) {
                $routes = [...$routes, ...$child->flatten($prefix, $middleware)];
                continue;
            }
            if ($child->path === '' && $prefix === '') {
                throw new InvalidApplication('A route path may be "" only inside a group with a prefix.');
            }
            $routes[] = [
                'route' => $child,
                'path' => $prefix . $child->path,
                'middleware' => [...$middleware, ...$child->getMiddleware()],
            ];
        }

        return $routes;
    }
}
