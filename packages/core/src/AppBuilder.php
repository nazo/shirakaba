<?php

declare(strict_types=1);

namespace Shirakaba\Core;

use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Shirakaba\Core\Description\ApplicationDescription;
use Shirakaba\Core\Description\ResponseDeclaration;
use Shirakaba\Core\Description\RouteDescription;
use Shirakaba\Core\Http\ErrorRenderer;
use Shirakaba\Core\Http\ProblemDetails;
use Shirakaba\Core\Http\ResponseContext;
use Shirakaba\Core\Http\TranslatesFailures;
use Shirakaba\Core\Internal\ErrorResponder;
use Shirakaba\Core\Internal\HandlerInvoker;
use Shirakaba\Core\Internal\LazyInvoker;
use Shirakaba\Core\Internal\MiddlewareNode;
use Shirakaba\Core\Internal\RouterHandler;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Routing\PathTemplate;
use Shirakaba\Core\Routing\Route;
use Shirakaba\Core\Routing\Router;
use Shirakaba\Http\Message\HttpFactory;

/**
 * Assembles an application in plain PHP (design/01-principles.md): global middleware, observers and
 * routes. build() validates everything and returns an immutable Application.
 *
 *     $app = new AppBuilder();
 *     $app->get('/users/{id}', show_user(...));
 *     $app->group('/admin', function (RouteGroup $admin) use ($auth): void {
 *         $admin->middleware($auth);
 *         $admin->get('/stats', stats(...));
 *     });
 *     $application = $app->build();
 */
final class AppBuilder
{
    private RouteGroup $routes;

    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    /** @var list<Observer> */
    private array $observers = [];

    /** @var list<TranslatesFailures> */
    private array $translators = [];

    private ?ErrorRenderer $errorRenderer = null;

    private ResponseContext $context;

    /**
     * Uses Shirakaba's own PSR-17 factory unless others are given.
     */
    public function __construct(
        ?ResponseFactoryInterface $responses = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        $factory = $responses === null || $streams === null ? new HttpFactory() : null;
        $this->context = new ResponseContext($responses ?? $factory ?? new HttpFactory(), $streams ?? $factory ?? new HttpFactory());
        $this->routes = new RouteGroup();
    }

    /**
     * Adds global middleware. It wraps routing, so it also runs for 404, 405 and automatic
     * OPTIONS responses (design/04-routing-and-handlers.md).
     */
    public function middleware(MiddlewareInterface ...$middleware): static
    {
        $this->middleware = [...$this->middleware, ...array_values($middleware)];

        return $this;
    }

    /**
     * Adds translators that turn library exceptions into HTTP failures (design/08-database.md). Global
     * middleware that implements TranslatesFailures is added without this call.
     */
    public function translateFailures(TranslatesFailures ...$translators): static
    {
        $this->translators = [...$this->translators, ...array_values($translators)];

        return $this;
    }

    /**
     * Replaces the default Problem Details rendering of errors (design/05-errors-and-observability.md).
     */
    public function renderErrorsWith(ErrorRenderer $renderer): static
    {
        $this->errorRenderer = $renderer;

        return $this;
    }

    /**
     * Adds observers; each receives every request's events, in the order added.
     */
    public function observe(Observer ...$observers): static
    {
        $this->observers = [...$this->observers, ...array_values($observers)];

        return $this;
    }

    public function get(string $path, callable $handler): RouteDefinition
    {
        return $this->routes->get($path, $handler);
    }

    public function post(string $path, callable $handler): RouteDefinition
    {
        return $this->routes->post($path, $handler);
    }

    public function put(string $path, callable $handler): RouteDefinition
    {
        return $this->routes->put($path, $handler);
    }

    public function patch(string $path, callable $handler): RouteDefinition
    {
        return $this->routes->patch($path, $handler);
    }

    public function delete(string $path, callable $handler): RouteDefinition
    {
        return $this->routes->delete($path, $handler);
    }

    public function head(string $path, callable $handler): RouteDefinition
    {
        return $this->routes->head($path, $handler);
    }

    public function options(string $path, callable $handler): RouteDefinition
    {
        return $this->routes->options($path, $handler);
    }

    /**
     * @param non-empty-list<string> $methods
     */
    public function route(array $methods, string $path, callable $handler): RouteDefinition
    {
        return $this->routes->route($methods, $path, $handler);
    }

    /**
     * @param Closure(RouteGroup): void $define
     */
    public function group(string $prefix, Closure $define): RouteGroup
    {
        return $this->routes->group($prefix, $define);
    }

    /**
     * Validates routes, handler signatures and extractors, and freezes the result.
     *
     * With $lazy, handlers are planned (reflected and validated) only when their route is
     * first requested, so building costs little more than parsing the routes. This suits
     * PHP-FPM, where the application is built for every request but serves one route; it
     * moves handler errors from build time to request time (a 500), so keep a test or CI
     * step that builds the application without $lazy (design/04-routing-and-handlers.md).
     *
     * @throws InvalidApplication when anything is declared wrongly
     */
    public function build(bool $lazy = false): Application
    {
        $translators = [
            ...$this->translators,
            ...array_values(array_filter($this->middleware, static fn (MiddlewareInterface $middleware): bool => $middleware instanceof TranslatesFailures)),
        ];
        $errors = new ErrorResponder($this->errorRenderer ?? new ProblemDetails($this->context), $translators);
        $router = new RouterHandler(
            Router::build($this->plan(describe: false, lazy: $lazy)['routes']),
            $errors,
            $this->context->streams,
            $this->context->responses,
        );

        return new Application(MiddlewareNode::wrap($this->middleware, $router), $errors, $this->observers);
    }

    /**
     * Describes the routes for tooling such as OpenAPI generation (design/04-routing-and-handlers.md). Runs the same
     * planning and validation as build(), so it fails on the same mistakes.
     *
     * @throws InvalidApplication when anything is declared wrongly
     */
    public function describe(): ApplicationDescription
    {
        $plan = $this->plan(describe: true);
        Router::build($plan['routes']);

        return new ApplicationDescription($plan['descriptions']);
    }

    /**
     * @return array{routes: list<Route>, descriptions: list<RouteDescription>}
     * @throws InvalidApplication when anything is declared wrongly
     */
    private function plan(bool $describe, bool $lazy = false): array
    {
        $routes = [];
        $descriptions = [];
        $names = [];
        foreach ($this->routes->flatten() as ['route' => $definition, 'path' => $path, 'middleware' => $middleware]) {
            $template = PathTemplate::parse($path);
            $name = $definition->getName();
            if ($name !== null) {
                if (isset($names[$name])) {
                    throw new InvalidApplication(sprintf('The route name "%s" is used by "%s" and "%s".', $name, $names[$name], $path));
                }
                $names[$name] = $path;
            }

            foreach ($definition->methods as $method) {
                $label = $method . ' ' . $path;
                if ($lazy) {
                    $routes[] = new Route(
                        $method,
                        $template,
                        MiddlewareNode::wrap($middleware, new LazyInvoker($definition->handler, $label, $template->parameterNames(), $this->context)),
                        $name,
                    );
                    continue;
                }
                $plan = HandlerInvoker::plan($definition->handler, $label, $template->parameterNames(), $this->context, $describe);
                $routes[] = new Route($method, $template, MiddlewareNode::wrap($middleware, $plan->invoker), $name);
                $descriptions[] = new RouteDescription(
                    $method,
                    $path,
                    $name,
                    $template->parameterNames(),
                    $plan->parameters,
                    $plan->undescribed,
                    $plan->responses,
                    [
                        ...$plan->declaredResponses,
                        // Route middleware may answer too, such as a rate limit's 429.
                        ...array_map(
                            static fn (
                                ResponseDeclaration $declaration,
                            ): \Shirakaba\Core\Description\ResponseDescription => $declaration->responseDescription(),
                            array_values(array_filter($middleware, static fn (object $layer): bool => $layer instanceof ResponseDeclaration)),
                        ),
                    ],
                    $definition->isDocumented(),
                    RouteDescription::handlerName($definition->handler),
                );
            }
        }

        return ['routes' => $routes, 'descriptions' => $descriptions];
    }
}
