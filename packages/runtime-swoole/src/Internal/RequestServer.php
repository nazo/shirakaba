<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Swoole\Internal;

use Closure;
use InvalidArgumentException;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Application;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestOptions;
use Shirakaba\Http\Message\Response;
use Throwable;

/**
 * Serves one request given as the arrays Swoole fills in, so it can be tested without Swoole.
 *
 * @internal
 */
final readonly class RequestServer
{
    private Emitter $emitter;

    /**
     * @param Closure(Throwable): void $onError
     */
    public function __construct(
        private RequestHandlerInterface $application,
        private Closure $onError,
    ) {
        $this->emitter = new Emitter();
    }

    /**
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $headers
     * @param array<array-key, mixed> $cookies
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files
     */
    public function serve(
        array $server,
        array $headers,
        array $cookies,
        array $query,
        array $post,
        array $files,
        string $body,
        bool $https,
        Connection $connection,
    ): void {
        try {
            $request = RequestConverter::convert($server, $headers, $cookies, $query, $post, $files, $body, $https);
        } catch (InvalidArgumentException) {
            $this->emitter->emit(self::plain(400, 'Bad Request'), $connection, static function (): void {});

            return;
        }

        $cancellation = new Cancellation();
        try {
            $response = $this->application instanceof Application
                ? $this->application->handleWith($request, new RequestOptions($cancellation))
                : $this->application->handle($request);
        } catch (Throwable $e) {
            ($this->onError)($e);
            $this->emitter->emit(self::plain(500, 'Internal Server Error'), $connection, static function (): void {});

            return;
        }

        try {
            $this->emitter->emit($response, $connection, static fn () => $cancellation->request('The client disconnected.'));
        } catch (Throwable $e) {
            // The status line may be out already; finishing the response is all that is left.
            ($this->onError)($e);
            $connection->end();
        }
    }

    private static function plain(int $status, string $text): Response
    {
        return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8'], $text);
    }
}
