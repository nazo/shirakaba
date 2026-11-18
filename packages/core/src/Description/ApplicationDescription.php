<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * Everything an application exposes, as returned by AppBuilder::describe() (design/04-routing-and-handlers.md).
 */
final readonly class ApplicationDescription
{
    /**
     * @param list<RouteDescription> $routes in declaration order
     */
    public function __construct(
        public array $routes,
    ) {}
}
