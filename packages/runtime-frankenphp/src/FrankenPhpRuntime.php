<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\FrankenPhp;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Application;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestOptions;
use Shirakaba\Http\Message\Response;
use Shirakaba\Sapi\SapiEmitter;
use Shirakaba\Sapi\ServerRequestBuilder;
use Throwable;

/**
 * Runs an application in FrankenPHP worker mode: the application is built once and serves
 * many requests (design/03-runtimes-and-scopes.md). Outside worker mode it handles the single current request,
 * like shirakaba/runtime-fpm.
 *
 *     // public/index.php, started with `frankenphp php-server --worker public/index.php`
 *     new FrankenPhpRuntime()->run((require __DIR__ . '/../app.php')->build());
 *
 * After each request it removes uploaded temporary files the application did not move and
 * collects garbage; after $maxRequests it returns so FrankenPHP starts a fresh worker.
 */
final readonly class FrankenPhpRuntime
{
    private SapiEmitter $emitter;

    /** @var Closure(Closure(): void): bool */
    private Closure $handleRequest;

    /** @var Closure(): ServerRequestInterface */
    private Closure $requestFactory;

    /**
     * @param positive-int $maxRequests
     * @param (Closure(Closure(): void): bool)|null $handleRequest defaults to frankenphp_handle_request()
     * @param (Closure(): ServerRequestInterface)|null $requestFactory defaults to the superglobals of the current request
     */
    public function __construct(
        ?SapiEmitter $emitter = null,
        private int $maxRequests = 1000,
        ?Closure $handleRequest = null,
        ?Closure $requestFactory = null,
    ) {
        $this->emitter = $emitter ?? new SapiEmitter();
        // Decided once: FRANKENPHP_WORKER is only visible before the first request.
        $worker = self::inWorkerMode();
        $this->handleRequest = $handleRequest ?? static fn (Closure $handler): bool => self::frankenphp($handler, $worker);
        $this->requestFactory = $requestFactory ?? ServerRequestBuilder::fromSuperglobals(...);
    }

    /**
     * Whether the script was started as a FrankenPHP worker. Call it before the first
     * request: inside a request, $_SERVER holds that request's values instead.
     */
    public static function inWorkerMode(): bool
    {
        return (
            function_exists('frankenphp_handle_request')
                && (($_SERVER['FRANKENPHP_WORKER'] ?? null) !== null || getenv('FRANKENPHP_WORKER') !== false)
        );
    }

    public function run(RequestHandlerInterface $application): void
    {
        // Keep the worker alive when a client disconnects mid-response.
        ignore_user_abort(true);

        for ($served = 0; $served < $this->maxRequests; $served++) {
            $more = ($this->handleRequest)(function () use ($application): void {
                $this->serve($application);
            });
            gc_collect_cycles();
            if (!$more) {
                return;
            }
        }
    }

    private function serve(RequestHandlerInterface $application): void
    {
        try {
            $request = ($this->requestFactory)();
        } catch (InvalidArgumentException) {
            $this->emitter->emit(new Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'Bad Request'));

            return;
        }

        try {
            $cancellation = new Cancellation();
            $response = $application instanceof Application
                ? $application->handleWith($request, new RequestOptions($cancellation))
                : $application->handle($request);
            $this->emitter->emit($response, static fn () => $cancellation->request('The client disconnected.'));
        } finally {
            self::removeUnmovedUploads($request->getUploadedFiles());
        }
    }

    /**
     * @param array<array-key, mixed> $files
     */
    private static function removeUnmovedUploads(array $files): void
    {
        foreach ($files as $file) {
            if (is_array($file)) {
                self::removeUnmovedUploads($file);
                continue;
            }
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }
            try {
                $path = $file->getStream()->getMetadata('uri');
            } catch (Throwable) {
                // Moved already, or not file-backed.
                continue;
            }
            if (is_string($path) && is_uploaded_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param Closure(): void $handler
     */
    private static function frankenphp(Closure $handler, bool $worker): bool
    {
        if (!$worker) {
            // Classic mode (or no FrankenPHP at all): serve the current request once.
            $handler();

            return false;
        }

        return frankenphp_handle_request($handler);
    }
}
