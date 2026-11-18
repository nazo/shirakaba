<?php

declare(strict_types=1);

namespace Shirakaba\Core\Observe;

/**
 * The route that matched a request, identified by its template rather than the raw path.
 */
final readonly class RouteSummary
{
    public function __construct(
        /** The route's method; differs from the request's for HEAD served by a GET route. */
        public string $method,
        public string $template,
        public ?string $name,
    ) {}
}
