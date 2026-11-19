<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Fpm\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Http\Message\Response;
use Shirakaba\Http\Message\ServerRequest;
use Shirakaba\Runtime\Fpm\FpmRuntime;
use Shirakaba\Sapi\SapiEmitter;
use Shirakaba\Sapi\ServerRequestBuilder;

#[CoversClass(SapiEmitter::class)]
#[CoversClass(FpmRuntime::class)]
final class EmitterAndRuntimeTest extends TestCase
{
    /** @var list<array{string, bool, int}> */
    private array $headers = [];

    private string $output = '';

    private function emitter(bool $headersSent = false): SapiEmitter
    {
        return new SapiEmitter(
            function (string $line, bool $replace, int $status): void {
                $this->headers[] = [$line, $replace, $status];
            },
            function (string $chunk): void {
                $this->output .= $chunk;
            },
            static fn (): bool => $headersSent,
        );
    }

    public function testEmitsStatusHeadersAndBody(): void
    {
        $response = new Response(201, ['Content-Type' => 'text/plain', 'Set-Cookie' => ['a=1', 'b=2']], 'created');

        $this->emitter()->emit($response);

        self::assertSame(
            [
                ['HTTP/1.1 201 Created',     true,  201],
                ['Content-Type: text/plain', true,  201],
                ['Set-Cookie: a=1',          false, 201],
                ['Set-Cookie: b=2',          false, 201],
            ],
            $this->headers,
        );
        self::assertSame('created', $this->output);
    }

    public function testEmitsLargeBodiesInChunksFromTheStart(): void
    {
        $response = new Response(body: str_repeat('x', 200_000));
        $response->getBody()->seek(1000);

        $this->emitter()->emit($response);

        self::assertSame(200_000, strlen($this->output));
    }

    public function testClosesTheBodyAfterEmitting(): void
    {
        $response = new Response(body: 'done');

        $this->emitter()->emit($response);

        self::assertFalse($response->getBody()->isReadable(), 'Emitters close bodies so streaming resources are released (design/03-runtimes-and-scopes.md).');
    }

    public function testStopsAndReportsWhenTheClientGoesAway(): void
    {
        $state = new \ArrayObject(['written' => 0, 'aborted' => false]);
        $emitter = new SapiEmitter(
            static function (): void {},
            static function (string $chunk) use ($state): void {
                $state['written'] = (int) $state['written'] + strlen($chunk);
            },
            static fn (): bool => false,
            static fn (): bool => $state['written'] >= (65_536 * 2),
        );
        $response = new Response(body: str_repeat('x', 65_536 * 10));
        $body = $response->getBody();

        $emitter->emit($response, static function () use ($state): void {
            $state['aborted'] = true;
        });

        self::assertTrue($state['aborted']);
        self::assertSame(65_536 * 2, $state['written'], 'Nothing is written after the disconnect is seen.');
        self::assertNull($body->detach(), 'The body was closed, so its producer and cleanup ran.');
    }

    public function testRefusesWhenHeadersWereSent(): void
    {
        $this->expectException(LogicException::class);
        $this->emitter(headersSent: true)->emit(new Response());
    }

    public function testRunsTheApplication(): void
    {
        $runtime = new FpmRuntime($this->emitter(), static fn (): ServerRequestInterface => ServerRequestBuilder::build(
            ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/hello'],
            [],
            [],
            [],
            [],
            \Shirakaba\Http\Message\Stream::fromString(),
        ));

        $runtime->run(self::application());

        self::assertSame('GET http://example.com/hello', $this->output);
    }

    public function testMalformedRequestIsA400WithoutReachingTheApplication(): void
    {
        $runtime = new FpmRuntime($this->emitter(), static fn (): ServerRequestInterface => throw new \InvalidArgumentException('Invalid Host header.'));

        $runtime->run(self::application());

        self::assertSame(['HTTP/1.1 400 Bad Request', true, 400], $this->headers[0] ?? null);
        self::assertSame('Bad Request', $this->output);
    }

    private static function application(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(body: $request->getMethod() . ' ' . $request->getUri());
            }
        };
    }

    public function testRequestFactoryDefaultsAreUsable(): void
    {
        // fromSuperglobals() works in CLI too, where most server values are absent.
        $request = ServerRequestBuilder::fromSuperglobals();

        self::assertInstanceOf(ServerRequest::class, $request);
    }
}
