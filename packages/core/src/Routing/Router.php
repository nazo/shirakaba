<?php

declare(strict_types=1);

namespace Shirakaba\Core\Routing;

use Shirakaba\Core\InvalidApplication;

/**
 * Immutable route table implementing the matching rules of design/04-routing-and-handlers.md.
 *
 * Routes are grouped by shape (templates matching exactly the same paths) and the shapes
 * are kept in precedence order, so the first shape matching a path is the one design/04-routing-and-handlers.md
 * selects. Its routes then decide between found, 405 and automatic OPTIONS.
 *
 * @internal
 */
final readonly class Router
{
    /**
     * @param list<array{template: PathTemplate, routes: array<string, Route>}> $shapes
     */
    private function __construct(
        private array $shapes,
    ) {}

    /**
     * @param list<Route> $routes
     * @throws InvalidApplication when two routes have the same method and shape
     */
    public static function build(array $routes): self
    {
        /** @var array<string, array{template: PathTemplate, routes: array<string, Route>}> $shapes */
        $shapes = [];
        foreach ($routes as $route) {
            $shape = $route->template->shape();
            $existing = $shapes[$shape]['routes'][$route->method] ?? null;
            if ($existing !== null) {
                throw new InvalidApplication(sprintf(
                    'Routes "%s %s" and "%s %s" match the same requests.',
                    $existing->method,
                    $existing->template->template,
                    $route->method,
                    $route->template->template,
                ));
            }
            $shapes[$shape] ??= ['template' => $route->template, 'routes' => []];
            $shapes[$shape]['routes'][$route->method] = $route;
        }

        // Sorting by precomputed keys is much cheaper than comparing templates pairwise,
        // which matters when PHP-FPM builds the application for every request.
        $keys = [];
        foreach ($shapes as $shape => $entry) {
            $keys[$shape] = $entry['template']->precedenceKey();
        }
        uksort($shapes, static fn (string $a, string $b): int => strcmp($keys[$a] ?? '', $keys[$b] ?? ''));

        return new self(array_values($shapes));
    }

    public function match(string $method, string $path): MatchResult
    {
        $segments = self::segments($path);
        if ($segments === null) {
            return MatchResult::invalidPath();
        }

        foreach ($this->shapes as $shape) {
            if ($shape['template']->match($segments) === null) {
                continue;
            }

            $routes = $shape['routes'];
            $route = $routes[$method] ?? null;
            $headFallback = false;
            if ($route === null && $method === 'HEAD') {
                $route = $routes['GET'] ?? null;
                $headFallback = $route !== null;
            }
            if ($route !== null) {
                $values = $route->template->match($segments) ?? [];

                return MatchResult::found($route, self::combine($route->template->parameterNames(), $values), $headFallback);
            }

            $allowed = array_keys($routes);
            if (isset($routes['GET'])) {
                $allowed[] = 'HEAD';
            }
            $allowed[] = 'OPTIONS';
            $allowed = array_values(array_unique($allowed));
            sort($allowed);

            return $method === 'OPTIONS'
                ? MatchResult::automaticOptions($allowed)
                : MatchResult::methodNotAllowed($allowed);
        }

        return MatchResult::notFound();
    }

    /**
     * Splits the path on "/" first and percent-decodes each segment once afterwards, so an
     * encoded slash never creates a segment boundary.
     *
     * @return non-empty-list<string>|null null when a segment decodes to a NUL byte
     */
    private static function segments(string $path): ?array
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $segments = [];
        foreach (explode('/', substr($path, 1)) as $raw) {
            $decoded = rawurldecode($raw);
            if (str_contains($decoded, "\0")) {
                return null;
            }
            $segments[] = $decoded;
        }

        return $segments;
    }

    /**
     * @param list<string> $names
     * @param list<string> $values
     * @return array<string, string>
     */
    private static function combine(array $names, array $values): array
    {
        $parameters = [];
        foreach ($names as $index => $name) {
            $parameters[$name] = $values[$index] ?? '';
        }

        return $parameters;
    }
}
