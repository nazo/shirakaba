<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\RoadRunner;

use Generator;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Application;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestOptions;
use Shirakaba\Runtime\RoadRunner\Internal\RequestConverter;
use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\Http\HttpWorkerInterface;
use Spiral\RoadRunner\Http\Request;
use Spiral\RoadRunner\Worker;
use Throwable;

/**
 * Runs an application as a RoadRunner HTTP worker: the application is built once and serves
 * requests until RoadRunner stops the worker (design/03-runtimes-and-scopes.md).
 *
 *     // worker.php, named by server.command in .rr.yaml
 *     new RoadRunnerRuntime()->run((require __DIR__ . '/app.php')->build());
 *
 * RoadRunner frames request bodies, stores uploads and removes them after the request, and
 * restarts workers after http.pool.max_jobs requests. A body whose end is not reached with
 * its first chunk is sent as a RoadRunner stream; when the client disconnects, the request's
 * Cancellation is requested and the body closed, so its cleanup runs. An exception that
 * escapes the application is reported to RoadRunner, which logs it and answers 500.
 */
final readonly class RoadRunnerRuntime
{
    private const int CHUNK_SIZE = 1 << 16;

    /**
     * @param HttpWorkerInterface|null $worker defaults to a worker connected to the RoadRunner that started this process
     */
    public function __construct(
        private ?HttpWorkerInterface $worker = null,
    ) {}

    public function run(RequestHandlerInterface $application): void
    {
        $worker = $this->worker ?? new HttpWorker(Worker::create());

        while (true) {
            try {
                $request = $worker->waitRequest();
                if ($request === null) {
                    // RoadRunner asked the worker to stop.
                    return;
                }
                $this->serve($worker, $application, $request);
            } catch (Throwable $e) {
                $worker->getWorker()->error((string) $e);
            } finally {
                gc_collect_cycles();
            }
        }
    }

    private function serve(HttpWorkerInterface $worker, RequestHandlerInterface $application, Request $request): void
    {
        try {
            $serverRequest = RequestConverter::convert($request);
        } catch (InvalidArgumentException) {
            $worker->respond(400, 'Bad Request', ['Content-Type' => ['text/plain; charset=utf-8']]);

            return;
        }

        $cancellation = new Cancellation();
        $response = $application instanceof Application
            ? $application->handleWith($serverRequest, new RequestOptions($cancellation))
            : $application->handle($serverRequest);
        $this->emit($worker, $response, $cancellation);
    }

    /**
     * Sends the response and closes its body, sent or not (design/03-runtimes-and-scopes.md).
     */
    private function emit(HttpWorkerInterface $worker, ResponseInterface $response, Cancellation $cancellation): void
    {
        $headers = $response->getHeaders();
        $body = $response->getBody();
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $first = $body->eof() ? '' : $body->read(self::CHUNK_SIZE);
            if ($first === '' || $body->eof()) {
                $worker->respond($response->getStatusCode(), $first, $headers);

                return;
            }
            $worker->respond($response->getStatusCode(), self::chunks($first, $body, $cancellation), $headers);
        } finally {
            $body->close();
        }
    }

    /**
     * @return Generator<int, string, mixed, null>
     */
    private static function chunks(string $first, StreamInterface $body, Cancellation $cancellation): Generator
    {
        try {
            yield $first;
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    // Streaming bodies never return "" before their end (design/03-runtimes-and-scopes.md).
                    return null;
                }
                yield $chunk;
            }
        } catch (StreamStoppedException) {
            // RoadRunner throws this into the generator when the client went away.
            $cancellation->request('The client disconnected.');
        }

        return null;
    }
}
