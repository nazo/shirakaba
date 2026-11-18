<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Swoole;

use Closure;
use LogicException;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Runtime\Swoole\Internal\RequestServer;
use Shirakaba\Runtime\Swoole\Internal\SwooleConnection;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Throwable;

/**
 * Runs an application on a Swoole HTTP server: each worker process keeps the application
 * and serves many requests, concurrently when coroutine hooks are enabled
 * (design/03-runtimes-and-scopes.md).
 *
 *     // server.php, started with `php server.php`
 *     $server = new Swoole\Http\Server('0.0.0.0', 8080);
 *     $server->set(['worker_num' => 4, 'max_request' => 1000]);
 *     new SwooleRuntime($server)->run((require __DIR__ . '/app.php')->build());
 *
 * The application is built before the server starts and copied into every worker, so it
 * must not open connections while it is built; Shirakaba's pools connect on first use.
 * Requests get the https scheme when the server's main port uses TLS.
 *
 * A request that cannot be represented as PSR-7 (for example an invalid Host header) gets a
 * 400 without reaching the application. A body whose end is not reached with its first
 * chunk is sent chunk by chunk; when the client disconnects, the request's Cancellation is
 * requested and the body closed, so its cleanup runs. An exception that escapes the
 * application is passed to $onError and answered with 500, so it does not end the worker
 * and the other requests it is serving.
 */
final readonly class SwooleRuntime
{
    /** The first release whose upload paths are always files Swoole created. */
    private const string MINIMUM_VERSION = '6.2.5';

    /** @var Closure(Throwable): void */
    private Closure $onError;

    /**
     * @param (Closure(Throwable): void)|null $onError receives exceptions that escape the application; defaults to error_log()
     */
    public function __construct(
        private Server $server,
        ?Closure $onError = null,
    ) {
        $this->onError = $onError ?? static function (Throwable $e): void {
            error_log((string) $e);
        };
    }

    /**
     * Registers the request handler and starts the server; returns when the server shuts down.
     *
     * @throws LogicException when the Swoole extension is missing or older than 6.2.5
     */
    public function run(RequestHandlerInterface $application): void
    {
        $version = phpversion('swoole');
        if ($version === false || version_compare($version, self::MINIMUM_VERSION, '<')) {
            throw new LogicException(sprintf('shirakaba/runtime-swoole needs the Swoole extension %s or later.', self::MINIMUM_VERSION));
        }

        $server = new RequestServer($application, $this->onError);
        $https = $this->server->ssl;
        $this->server->on('request', static function (Request $request, Response $response) use ($server, $https): void {
            $content = $request->getContent();
            $server->serve(
                $request->server ?? [],
                $request->header ?? [],
                $request->cookie ?? [],
                $request->get ?? [],
                $request->post ?? [],
                $request->files ?? [],
                $content === false ? '' : $content,
                $https,
                new SwooleConnection($response),
            );
        });
        $this->server->start();
    }
}
