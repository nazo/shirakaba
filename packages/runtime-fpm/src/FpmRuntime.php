<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Fpm;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Application;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestOptions;
use Shirakaba\Http\Message\Response;
use Shirakaba\Sapi\SapiEmitter;
use Shirakaba\Sapi\ServerRequestBuilder;

/**
 * Runs one request in a classic SAPI (PHP-FPM, Apache mod_php, the built-in server):
 *
 *     // public/index.php
 *     new FpmRuntime()->run(require __DIR__ . '/../app.php');
 *
 * A request that cannot be represented as PSR-7 (for example an invalid Host header)
 * gets a 400 without reaching the application. When the client disconnects during a
 * streamed response, the request's Cancellation is requested and the body closed, so its
 * cleanup runs.
 */
final readonly class FpmRuntime
{
    private SapiEmitter $emitter;

    /**
     * @param (\Closure(): ServerRequestInterface)|null $requestFactory defaults to ServerRequestBuilder::fromSuperglobals()
     */
    public function __construct(
        ?SapiEmitter $emitter = null,
        private ?\Closure $requestFactory = null,
    ) {
        $this->emitter = $emitter ?? new SapiEmitter();
    }

    public function run(RequestHandlerInterface $application): void
    {
        try {
            $request = $this->requestFactory !== null ? ($this->requestFactory)() : ServerRequestBuilder::fromSuperglobals();
        } catch (InvalidArgumentException) {
            $this->emitter->emit(new Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'Bad Request'));

            return;
        }

        // Without this, PHP ends the script at the first failed write, skipping cleanup.
        ignore_user_abort(true);
        $cancellation = new Cancellation();
        $response = $application instanceof Application
            ? $application->handleWith($request, new RequestOptions($cancellation))
            : $application->handle($request);
        $this->emitter->emit($response, static fn () => $cancellation->request('The client disconnected.'));
    }
}
