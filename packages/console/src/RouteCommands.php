<?php

declare(strict_types=1);

namespace Shirakaba\Console;

use Closure;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Core\Description\RouteDescription;

/**
 * Registers `routes` and `routes:show`, so people and coding agents can ask an
 * application which handler serves a request and what it accepts and returns:
 *
 *     RouteCommands::register($cli, static fn (): AppBuilder => require 'app.php');
 *     // app routes, app routes:show "PATCH /tasks/{id}", app routes:show tasks.update --json
 *
 * Both take --json; the shape is documented in the guide's command-line chapter.
 */
final readonly class RouteCommands
{
    /**
     * @param Closure(): AppBuilder $app built when a command runs, so help works without it
     */
    public static function register(ConsoleBuilder $cli, Closure $app, string $prefix = ''): void
    {
        $cli->command($prefix . 'routes', static function (Output $output, #[Option('Print JSON instead of a table')] bool $json) use ($app): int {
            $routes = $app()->describe()->routes;
            if ($json) {
                $output->line(self::encode(array_map(self::summary(...), $routes)));

                return 0;
            }
            $rows = array_map(static fn (RouteDescription $route): array => [$route->method, $route->template, $route->name ?? '-', self::relative($route->handler)], $routes);
            foreach (self::table($rows) as $line) {
                $output->line($line);
            }

            return 0;
        }, 'Lists the routes: method, path, name and handler.');

        $cli->command($prefix . 'routes:show', static function (
            Output $output,
            #[Argument('A route name, or a method and path such as "PATCH /tasks/{id}"')] string $route,
            #[Option('Print JSON instead of text')] bool $json,
        ) use ($app): int {
            $routes = $app()->describe()->routes;
            $matches = array_values(array_filter($routes, static fn (RouteDescription $candidate): bool => $candidate->name === $route
                || strcasecmp($candidate->method . ' ' . $candidate->template, $route) === 0));
            if ($matches === []) {
                throw new UsageError(sprintf('No route "%s"; run routes to list them.', $route));
            }
            if ($json) {
                $output->line(self::encode(count($matches) === 1 ? self::details($matches[0]) : array_map(self::details(...), $matches)));

                return 0;
            }
            foreach ($matches as $index => $match) {
                if ($index > 0) {
                    $output->line();
                }
                foreach (self::text($match) as $line) {
                    $output->line($line);
                }
            }

            return 0;
        }, 'Shows one route: its handler, parameters and responses.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(RouteDescription $route): array
    {
        return [
            'method' => $route->method,
            'path' => $route->template,
            'name' => $route->name,
            'handler' => $route->handler,
            'documented' => $route->documented,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function details(RouteDescription $route): array
    {
        return [
            ...self::summary($route),
            'parameters' => array_map(static fn (ParameterDescription $parameter): array => [
                'source' => $parameter->source,
                'name' => $parameter->name,
                'parameter' => $parameter->parameter,
                'type' => Introspection::type($parameter->type),
                'required' => $parameter->required,
                ...($parameter->hasDefault ? ['default' => $parameter->default] : []),
                ...($parameter->constraints === [] ? [] : ['constraints' => Introspection::constraints($parameter->constraints)]),
                ...($parameter->mediaTypes === [] ? [] : ['mediaTypes' => $parameter->mediaTypes]),
            ], $route->parameters),
            'undescribedParameters' => $route->undescribed,
            'responses' => array_map(static fn (ResponseDescription $response): array => [
                'status' => $response->status,
                'contentType' => $response->contentType,
                'type' => $response->body === null ? null : Introspection::type($response->body),
                'description' => $response->description,
            ], $route->declaredResponses),
            'returns' => $route->responses,
        ];
    }

    /**
     * @return list<string>
     */
    private static function text(RouteDescription $route): array
    {
        $lines = [
            $route->method . ' ' . $route->template,
            'name: ' . ($route->name ?? '-'),
            'handler: ' . self::relative($route->handler),
        ];
        if (!$route->documented) {
            $lines[] = 'undocumented: left out of OpenAPI';
        }
        $lines[] = 'parameters:';
        foreach ($route->parameters as $parameter) {
            $where = $parameter->source === ParameterDescription::INTERNAL ? 'internal' : $parameter->source . ' ' . ($parameter->name === '' ? $parameter->parameter : $parameter->name);
            $notes = [$parameter->required ? 'required' : 'optional'];
            if ($parameter->hasDefault) {
                $notes[] = 'default ' . var_export($parameter->default, true);
            }
            foreach (Introspection::constraints($parameter->constraints) as $keyword => $value) {
                $notes[] = $keyword . ' ' . (is_scalar($value) ? var_export($value, true) : json_encode($value));
            }
            $lines[] = sprintf('  %s: %s $%s (%s)', $where, Introspection::typeName($parameter->type), $parameter->parameter, implode(', ', $notes));
        }
        foreach ($route->undescribed as $parameter) {
            $lines[] = sprintf('  $%s (an extractor that does not describe itself)', $parameter);
        }
        $lines[] = 'responses:';
        foreach ($route->declaredResponses as $response) {
            $lines[] = rtrim(sprintf('  %d %s %s %s', $response->status, $response->contentType, $response->body === null ? '' : Introspection::typeName($response->body), $response->description));
        }
        if ($route->declaredResponses === []) {
            $lines[] = '  (none declared; returns ' . ($route->responses === [] ? 'an undeclared type' : implode('|', $route->responses)) . ')';
        }

        return $lines;
    }

    /**
     * @param list<list<string>> $rows
     * @return list<string>
     */
    private static function table(array $rows): array
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, mb_strlen($cell));
            }
        }

        return array_map(static function (array $row) use ($widths): string {
            $cells = [];
            foreach ($row as $column => $cell) {
                $cells[] = $cell . str_repeat(' ', ($widths[$column] ?? 0) - mb_strlen($cell));
            }

            return rtrim(implode('  ', $cells));
        }, $rows);
    }

    /**
     * Paths in closure names relative to the working directory, for shorter output.
     */
    private static function relative(string $handler): string
    {
        $cwd = getcwd();

        return $cwd === false ? $handler : str_replace($cwd . '/', '', $handler);
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
