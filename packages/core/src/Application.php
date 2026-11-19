<?php

declare(strict_types=1);

namespace Shirakaba\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Internal\ErrorResponder;
use Shirakaba\Core\Internal\SafeObservation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Scope\RequestOptions;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopeOwningBody;
use Throwable;

/**
 * A built, immutable application: a PSR-15 request handler.
 *
 * For each request it creates the request scope, runs the global middleware and the
 * router, renders failures that escape global middleware, and closes the scope before
 * returning (design/03-runtimes-and-scopes.md), unless a streaming body takes that over (design/03-runtimes-and-scopes.md). It holds no per-request state, so one instance serves any number
 * of requests, also concurrently.
 */
final readonly class Application implements RequestHandlerInterface
{
    /**
     * @param list<Observer> $observers
     * @internal created by AppBuilder::build()
     */
    public function __construct(
        private RequestHandlerInterface $pipeline,
        private ErrorResponder $errors,
        private array $observers,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleWith($request, new RequestOptions());
    }

    /**
     * Handles a request with a runtime-provided cancellation and deadline.
     */
    public function handleWith(ServerRequestInterface $request, RequestOptions $options): ResponseInterface
    {
        $propagation = [];
        if ($this->observers !== []) {
            foreach (RequestSummary::PROPAGATION_HEADERS as $header) {
                $value = $request->getHeaderLine($header);
                if ($value !== '') {
                    $propagation[$header] = $value;
                }
            }
        }
        $observation = SafeObservation::begin(
            $this->observers,
            new RequestSummary($request->getMethod(), $request->getProtocolVersion(), $propagation),
        );
        $scope = new RequestScope($options->cancellation, $options->deadline, $observation);

        $adopted = false;
        try {
            try {
                $response = $this->pipeline->handle($request->withAttribute(RequestScope::class, $scope));
            } catch (Throwable $e) {
                $observation->failed($e);
                $response = $this->errors->throwable($e, $request);
            }

            // A streaming body keeps using request resources and closes the scope itself (design/03-runtimes-and-scopes.md).
            $body = $response->getBody();
            if ($body instanceof ScopeOwningBody) {
                $body->adoptScope($scope->close(...));
                $adopted = true;
            }
        } finally {
            if (!$adopted) {
                $scope->close();
            }
        }

        $observation->responseReady($response->getStatusCode());

        return $response;
    }
}
