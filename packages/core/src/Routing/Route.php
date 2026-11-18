<?php

declare(strict_types=1);

namespace Shirakaba\Core\Routing;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * A built route for one method: its template and the handler pipeline that serves it.
 *
 * @internal
 */
final readonly class Route
{
    public function __construct(
        public string $method,
        public PathTemplate $template,
        /** The route's group and route middleware wrapped around its invoker. */
        public RequestHandlerInterface $handler,
        public ?string $name,
    ) {}
}
