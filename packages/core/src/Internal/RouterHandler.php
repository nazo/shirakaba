<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Http\Failure;
use Shirakaba\Core\Observe\RouteSummary;
use Shirakaba\Core\Routing\MatchStatus;
use Shirakaba\Core\Routing\Router;
use Shirakaba\Core\Scope\RequestScope;
use Throwable;

/**
 * The innermost handler of the global pipeline: matches the route, answers 400/404/405
 * and automatic OPTIONS, and renders failures of matched routes so that global middleware
 * sees an error response (design/04-routing-and-handlers.md and design/05-errors-and-observability.md).
 *
 * @internal
 */
final readonly class RouterHandler implements RequestHandlerInterface
{
    public function __construct(
        private Router $router,
        private ErrorResponder $errors,
        private StreamFactoryInterface $streams,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->router->match($request->getMethod(), $request->getUri()->getPath());
        $allow = implode(', ', $result->allowedMethods);

        if ($result->status === MatchStatus::InvalidPath) {
            return $this->errors->failure(new Failure(400, 'The request path contains a NUL byte.'), $request);
        }
        if ($result->status === MatchStatus::NotFound || $result->route === null) {
            return match ($result->status) {
                MatchStatus::MethodNotAllowed => $this->errors->failure(new Failure(405, headers: ['Allow' => $allow]), $request),
                MatchStatus::AutomaticOptions => $this->responses->createResponse(204)->withHeader('Allow', $allow),
                default => $this->errors->failure(new Failure(404), $request),
            };
        }

        $route = $result->route;
        $scope = RequestScope::of($request);
        $scope->observation()->matched(new RouteSummary($route->method, $route->template->template, $route->name));

        try {
            $response = $route->handler->handle($request->withAttribute(RouteParameters::class, new RouteParameters($result->parameters)));
        } catch (Throwable $e) {
            $scope->observation()->failed($e);
            $response = $this->errors->throwable($e, $request);
        }

        return $result->headFallback ? $response->withBody($this->streams->createStream()) : $response;
    }
}
