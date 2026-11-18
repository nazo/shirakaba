<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\RoadRunner\Tests;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Runtime\RoadRunner\Internal\RequestConverter;
use Shirakaba\Runtime\RoadRunner\RoadRunnerRuntime;
use Shirakaba\Streaming\Streamed;
use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Spiral\RoadRunner\Http\HttpWorkerInterface;
use Spiral\RoadRunner\Http\Request;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;

/**
 * Drives the worker loop with a fake RoadRunner worker, since RoadRunner itself is
 * exercised by the runtime conformance suite.
 */
#[CoversClass(RoadRunnerRuntime::class)]
#[CoversClass(RequestConverter::class)]
final class RoadRunnerRuntimeTest extends TestCase
{
    /**
     * @param list<Request> $requests
     * @param bool $stopStreams whether the client disconnects after the first chunk of a stream
     */
    private static function worker(array $requests, bool $stopStreams = false): FakeHttpWorker
    {
        return new FakeHttpWorker($requests, $stopStreams);
    }

    /**
     * @param array<non-empty-string, list<string>> $headers
     */
    private static function request(string $uri, array $headers = []): Request
    {
        return new Request(remoteAddr: '192.0.2.1', protocol: 'HTTP/1.1', method: 'GET', uri: $uri, headers: $headers);
    }

    public function testServesManyRequestsWithIsolatedScopes(): void
    {
        $app = new AppBuilder();
        $app->get('/whoami', static function (ServerRequestInterface $request, RequestScope $scope): Text {
            $seen = $scope->find(\stdClass::class);
            $scope->set(new \stdClass());

            return new Text($request->getHeaderLine('X-User') . ($seen === null ? ' fresh' : ' leaked'));
        });
        $worker = self::worker([
            self::request('http://localhost/whoami', ['Host' => ['localhost'], 'X-User' => ['alice']]),
            self::request('http://localhost/whoami', ['Host' => ['evil.example/path']]),
            self::request('http://localhost/whoami', ['Host' => ['localhost'], 'X-User' => ['bob']]),
        ]);

        new RoadRunnerRuntime($worker)->run($app->build());

        self::assertSame([200, 400, 200], array_column($worker->responses, 'status'));
        self::assertSame(['alice fresh', 'Bad Request', 'bob fresh'], array_column($worker->responses, 'body'));
        self::assertSame(['text/plain; charset=utf-8'], $worker->responses[0]['headers']['Content-Type'] ?? null);
    }

    public function testConvertsTheRequest(): void
    {
        $upload = tempnam(sys_get_temp_dir(), 'shirakaba-rr-');
        self::assertIsString($upload);
        file_put_contents($upload, 'hello');
        $request = RequestConverter::convert(new Request(
            remoteAddr: '192.0.2.1',
            protocol: 'HTTP/2.0',
            method: 'POST',
            uri: 'https://example.com:8443/items?page=2',
            headers: [
                'Host' => ['example.com:8443'],
                'Content-Type' => ['multipart/form-data; boundary=x'],
                'Accept' => ['text/html', 'application/json'],
                'Cookie' => ['a=1', 'b=2'],
                'X_Forwarded_For' => ['203.0.113.9'],
            ],
            cookies: ['a' => '1', 'b' => '2'],
            uploads: ['doc' => ['name' => 'a.txt', 'mime' => 'text/plain', 'size' => 5, 'error' => 0, 'tmpName' => $upload]],
            attributes: [],
            query: ['page' => '2'],
            body: '{"title":"x"}',
            parsed: true,
        ));
        @unlink($upload);

        self::assertSame('https://example.com:8443/items?page=2', (string) $request->getUri());
        self::assertSame('2.0', $request->getProtocolVersion());
        self::assertSame('text/html, application/json', $request->getHeaderLine('Accept'));
        self::assertSame('a=1; b=2', $request->getHeaderLine('Cookie'));
        self::assertFalse($request->hasHeader('X-Forwarded-For'), 'A header with underscores cannot pose as one with dashes.');
        self::assertSame(['page' => '2'], $request->getQueryParams());
        self::assertSame(['title' => 'x'], $request->getParsedBody());
        self::assertSame('', (string) $request->getBody());
        self::assertSame('192.0.2.1', $request->getServerParams()['REMOTE_ADDR'] ?? null);
        $file = $request->getUploadedFiles()['doc'] ?? null;
        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame(['a.txt', 'text/plain', 5, UPLOAD_ERR_OK], [$file->getClientFilename(), $file->getClientMediaType(), $file->getSize(), $file->getError()]);
    }

    public function testSendsSmallBodiesWhole(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Text => new Text('ok'));
        $worker = self::worker([self::request('http://localhost/', ['Host' => ['localhost']])]);

        new RoadRunnerRuntime($worker)->run($app->build());

        self::assertSame([['status' => 200, 'body' => 'ok', 'streamed' => false]], array_map(
            static fn (array $response): array => ['status' => $response['status'], 'body' => $response['body'], 'streamed' => $response['streamed']],
            $worker->responses,
        ));
    }

    public function testStreamsBodiesAndCleansUpWhenTheClientDisconnects(): void
    {
        $log = new Log();
        $app = new AppBuilder();
        $app->get('/stream', static function (RequestScope $scope) use ($log): Streamed {
            $cancellation = $scope->cancellation();
            $scope->defer(static function () use ($log, $cancellation): void {
                $log->entries[] = $cancellation->isRequested() ? 'cancelled' : 'finished';
            });

            return new Streamed((static function (): Generator {
                foreach (['one', 'two', 'three'] as $chunk) {
                    yield $chunk . "\n";
                }
            })(), 'text/plain');
        });
        $request = self::request('http://localhost/stream', ['Host' => ['localhost']]);

        $complete = self::worker([$request]);
        new RoadRunnerRuntime($complete)->run($app->build());
        $stopped = self::worker([$request], stopStreams: true);
        new RoadRunnerRuntime($stopped)->run($app->build());

        self::assertSame([["one\ntwo\nthree\n", true]], array_map(static fn (array $r): array => [$r['body'], $r['streamed']], $complete->responses));
        self::assertSame([["one\n", true]], array_map(static fn (array $r): array => [$r['body'], $r['streamed']], $stopped->responses));
        self::assertSame(['finished', 'cancelled'], $log->entries);
    }

    public function testReportsExceptionsAndKeepsServing(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Text => new Text('ok'));
        $failing = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };
        $worker = self::worker([self::request('http://localhost/', ['Host' => ['localhost']])]);

        new RoadRunnerRuntime($worker)->run($failing);

        self::assertSame([], $worker->responses);
        self::assertCount(1, $worker->worker->errors);
        self::assertStringContainsString('boom', implode("\n", $worker->worker->errors));
    }
}

/**
 * Hands out queued requests and records what the runtime responds, consuming streams the
 * way spiral/roadrunner-http does.
 */
final class FakeHttpWorker implements HttpWorkerInterface
{
    /** @var list<array{status: int, body: string, headers: array<array-key, array<array-key, string>>, streamed: bool}> */
    public array $responses = [];

    public readonly FakeWorker $worker;

    /**
     * @param list<Request> $requests
     */
    public function __construct(
        private array $requests,
        private readonly bool $stopStreams,
    ) {
        $this->worker = new FakeWorker();
    }

    public function getWorker(): WorkerInterface
    {
        return $this->worker;
    }

    public function waitRequest(): ?Request
    {
        return array_shift($this->requests);
    }

    public function respond(int $status, string|Generator $body, array $headers = [], bool $endOfStream = true): void
    {
        $streamed = $body instanceof Generator;
        if ($body instanceof Generator) {
            $content = '';
            while ($body->valid()) {
                $current = $body->current();
                $content .= is_string($current) ? $current : '';
                if ($this->stopStreams) {
                    $body->throw(new StreamStoppedException());
                    break;
                }
                $body->next();
            }
            $body = $content;
        }
        $this->responses[] = ['status' => $status, 'body' => $body, 'headers' => $headers, 'streamed' => $streamed];
    }
}

final class Log
{
    /** @var list<string> */
    public array $entries = [];
}

final class FakeWorker implements WorkerInterface
{
    /** @var list<string> */
    public array $errors = [];

    public function waitPayload(): ?Payload
    {
        return null;
    }

    public function respond(Payload $payload): void {}

    public function error(string $error): void
    {
        $this->errors[] = $error;
    }

    public function stop(): void {}

    public function hasPayload(?string $class = null): bool
    {
        return false;
    }

    public function getPayload(?string $class = null): ?Payload
    {
        return null;
    }
}
