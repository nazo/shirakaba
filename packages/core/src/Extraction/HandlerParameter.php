<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use ReflectionParameter;

/**
 * A handler parameter being planned, with the route it belongs to.
 */
final readonly class HandlerParameter
{
    /**
     * @param list<string> $routeParameters names of the route template's parameters
     */
    public function __construct(
        public ReflectionParameter $reflection,
        /** The route as "METHOD /template", for error messages. */
        public string $route,
        public array $routeParameters,
    ) {}

    public function name(): string
    {
        return $this->reflection->getName();
    }

    /**
     * Prefix for error messages, such as 'Route "GET /users/{id}", parameter $id'.
     */
    public function describe(): string
    {
        return sprintf('Route "%s", parameter $%s', $this->route, $this->reflection->getName());
    }
}
