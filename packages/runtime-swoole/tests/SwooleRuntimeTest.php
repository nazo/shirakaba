<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Swoole\Tests;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Http\Message\Response;
use Shirakaba\Runtime\Swoole\Internal\Connection;
use Shirakaba\Runtime\Swoole\Internal\Emitter;
use Shirakaba\Runtime\Swoole\Internal\RequestConverter;
use Shirakaba\Runtime\Swoole\Internal\RequestServer;
use Shirakaba\Streaming\Streamed;
use Throwable;

/**
 * Serves requests given as the arrays Swoole fills in and records what reaches the
 * connection, since Swoole itself is exercised by the runtime conformance suite.
 */
#[CoversClass(RequestServer::class)]
#[CoversClass(RequestConverter::class)]
#[CoversClass(Emitter::class)]
final class SwooleRuntimeTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private static function serve(
        RequestHandlerInterface $application,
        string $path,
        array $headers = ['host' => 'localhost'],
        ?FakeConnection $connection = null,
        ?Log $errors = null,
    ): FakeConnection {
        $connection ??= new FakeConnection();
        $server = new RequestServer($application, static function (Throwable $e) use ($errors): void {
            if ($errors !== null) {
                $errors->entries[] = $e->getMessage();
            }
        });
        $server->serve(
            ['request_method' => 'GET', 'request_uri' => $path, 'server_protocol' => 'HTTP/1.1', 'server_port' => 9501, 'remote_addr' => '192.0.2.1'],
            $headers,
            [],
            [],
            [],
            [],
            '',
            false,
            $connection,
        );

        return $connection;
    }

    public function testServesManyRequestsWithIsolatedScopes(): void
    {
        $app = new AppBuilder();
        $app->get('/whoami', static function (ServerRequestInterface $request, RequestScope $scope): Text {
            $seen = $scope->find(\stdClass::class);
            $scope->set(new \stdClass());

            return new Text($request->getHeaderLine('X-User') . ($seen === null ? ' fresh' : ' leaked'));
        });
        $application = $app->build();

        $alice = self::serve($application, '/whoami', ['host' => 'localhost', 'x-user' => 'alice']);
        $evil = self::serve($application, '/whoami', ['host' => 'evil.example/path']);
        $bob = self::serve($application, '/whoami', ['host' => 'localhost', 'x-user' => 'bob']);

        self::assertSame([200, 'alice fresh'], [$alice->status, $alice->body]);
        self::assertSame([400, 'Bad Request'], [$evil->status, $evil->body]);
        self::assertSame([200, 'bob fresh'], [$bob->status, $bob->body]);
        self::assertSame(['text/plain; charset=utf-8'], $alice->headers['Content-Type'] ?? null);
        self::assertFalse($alice->chunked);
    }

    public function testConvertsTheRequest(): void
    {
        $upload = tempnam(sys_get_temp_dir(), 'shirakaba-swoole-');
        self::assertIsString($upload);
        file_put_contents($upload, 'hello');
        $request = RequestConverter::convert(
            [
                'request_method' => 'POST',
                'request_uri' => '/items',
                'query_string' => 'page=2',
                'server_protocol' => 'HTTP/1.1',
                'server_port' => 9501,
                'remote_addr' => '192.0.2.1',
            ],
            ['host' => 'example.com', 'content-type' => 'application/x-www-form-urlencoded', 'x_forwarded_for' => '203.0.113.9'],
            ['a' => '1'],
            ['page' => '2'],
            ['title' => 'x'],
            ['file' => ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => $upload, 'error' => 0, 'size' => 5]],
            'title=x',
            true,
        );
        @unlink($upload);

        self::assertSame('https://example.com/items?page=2', (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertFalse($request->hasHeader('X-Forwarded-For'), 'A header with underscores cannot pose as one with dashes.');
        self::assertSame(['a' => '1'], $request->getCookieParams());
        self::assertSame(['page' => '2'], $request->getQueryParams());
        self::assertSame(['title' => 'x'], $request->getParsedBody());
        self::assertSame('title=x', (string) $request->getBody());
        self::assertSame('9501', $request->getServerParams()['SERVER_PORT'] ?? null);
        $file = $request->getUploadedFiles()['file'] ?? null;
        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame(['a.txt', 5], [$file->getClientFilename(), $file->getSize()]);
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

            return new Streamed(
                (static function (): Generator {
                    foreach (['one', 'two', 'three'] as $chunk) {
                        yield $chunk . "\n";
                    }
                })(),
                'text/plain',
            );
        });
        $application = $app->build();

        $complete = self::serve($application, '/stream');
        $gone = self::serve($application, '/stream', connection: new FakeConnection(acceptedWrites: 1));

        self::assertSame("one\ntwo\nthree\n", $complete->body);
        self::assertTrue($complete->chunked);
        self::assertTrue($complete->ended);
        self::assertSame("one\n", $gone->body);
        self::assertFalse($gone->ended);
        self::assertSame(['finished', 'cancelled'], $log->entries);
    }

    public function testDropsFramingHeaders(): void
    {
        $connection = new FakeConnection();
        new Emitter()->emit(
            new Response(200, ['Content-Length' => '2', 'Transfer-Encoding' => 'chunked', 'Set-Cookie' => ['a=1', 'b=2']], 'ok'),
            $connection,
            static function (): void {},
        );

        self::assertSame(['Set-Cookie' => ['a=1', 'b=2']], $connection->headers);
        self::assertSame('ok', $connection->body);
    }

    public function testAnswersExceptionsWith500AndReportsThem(): void
    {
        $failing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };
        $errors = new Log();

        $connection = self::serve($failing, '/', errors: $errors);

        self::assertSame([500, 'Internal Server Error'], [$connection->status, $connection->body]);
        self::assertSame(['boom'], $errors->entries);
    }
}

final class Log
{
    /** @var list<string> */
    public array $entries = [];
}

final class FakeConnection implements Connection
{
    public int $status = 0;

    /** @var array<string, list<string>> */
    public array $headers = [];

    public string $body = '';

    public bool $chunked = false;

    public bool $ended = false;

    public function __construct(
        private ?int $acceptedWrites = null,
    ) {}

    public function status(int $code, string $reason): void
    {
        $this->status = $code;
    }

    public function header(string $name, array $values): void
    {
        $this->headers[$name] = $values;
    }

    public function write(string $chunk): bool
    {
        if ($this->acceptedWrites !== null && $this->acceptedWrites-- <= 0) {
            return false;
        }
        $this->chunked = true;
        $this->body .= $chunk;

        return true;
    }

    public function end(string $content = ''): void
    {
        $this->body .= $content;
        $this->ended = true;
    }
}
