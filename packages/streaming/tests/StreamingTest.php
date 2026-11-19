<?php

declare(strict_types=1);

namespace Shirakaba\Streaming\Tests;

use Generator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Streaming\GeneratorStream;
use Shirakaba\Streaming\Sse;
use Shirakaba\Streaming\SseEvent;
use Shirakaba\Streaming\Streamed;

#[CoversClass(GeneratorStream::class)]
#[CoversClass(Streamed::class)]
#[CoversClass(Sse::class)]
#[CoversClass(SseEvent::class)]
final class StreamingTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    private function client(): TestClient
    {
        $app = new AppBuilder();
        $app->get('/export', function (RequestScope $scope): Streamed {
            $this->log[] = 'handler';
            $scope->defer(function (): void {
                $this->log[] = 'released';
            });

            return new Streamed($this->rows(), 'text/csv');
        });
        $app->get('/events', static fn (): Sse => new Sse([new SseEvent("line 1\nline 2", 'greeting', '1', 3000), null, new SseEvent('bye')]));

        return new TestClient($app->build());
    }

    /**
     * @return Generator<string>
     */
    private function rows(): Generator
    {
        foreach (['a,1', '', 'b,2'] as $row) {
            $this->log[] = 'row ' . $row;
            yield $row === '' ? '' : $row . "\n";
        }
    }

    public function testScopeIsReleasedAfterTheBodyIsConsumedNotWhenTheHandlerReturns(): void
    {
        $response = $this->client()->get('/export');

        self::assertSame(['handler'], $this->log, 'Nothing is produced or released before the body is read.');
        self::assertSame('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertSame("a,1\nb,2\n", (string) $response->getBody());
        self::assertSame(['handler', 'row a,1', 'row ', 'row b,2', 'released'], $this->log);
    }

    public function testClosingAnUnreadBodyReleasesTheScope(): void
    {
        $response = $this->client()->get('/export');
        $response->getBody()->close();

        self::assertSame(['handler', 'released'], $this->log);
    }

    public function testDestroyingAnUnreadBodyReleasesTheScope(): void
    {
        $this->client()->get('/export');

        self::assertSame(['handler', 'released'], $this->log);
    }

    public function testHeadReleasesTheScopeWithoutProducingTheBody(): void
    {
        $response = $this->client()->head('/export');

        self::assertSame('', (string) $response->getBody());
        self::assertSame(['handler', 'released'], $this->log);
    }

    public function testReadsInSmallSlicesAndReportsEnd(): void
    {
        $stream = new GeneratorStream(['abc', '', 'de']);

        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
        self::assertSame('ab', $stream->read(2));
        self::assertSame('c', $stream->read(2));
        self::assertSame('de', $stream->read(10));
        self::assertSame('', $stream->read(10));
        self::assertTrue($stream->eof());
        self::assertSame(5, $stream->tell());
    }

    public function testProducerFailureReleasesAndPropagates(): void
    {
        $released = false;
        $stream = new GeneratorStream(
            (static function (): Generator {
                yield 'a';

                throw new LogicException('producer broke');
            })(),
        );
        $stream->adoptScope(static function () use (&$released): void {
            $released = true;
        });

        self::assertSame('a', $stream->read(10));
        try {
            $stream->read(10);
            self::fail('The producer failure must propagate.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(LogicException::class, $e);
        }
        self::assertTrue($released);
        self::assertTrue($stream->eof());
    }

    public function testRejectsNonStringChunks(): void
    {
        // Reflection bypasses the static iterable<string> contract, as untyped producers would.
        $stream = new \ReflectionClass(GeneratorStream::class)->newInstance([1]);

        $this->expectException(RuntimeException::class);
        $stream->read(1);
    }

    public function testRejectsWritesAndSeeks(): void
    {
        $stream = new GeneratorStream([]);

        self::assertFalse($stream->isWritable());
        $this->expectException(RuntimeException::class);
        $stream->rewind();
    }

    public function testEncodesServerSentEvents(): void
    {
        $response = $this->client()->get('/events');

        self::assertSame('text/event-stream; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            "event: greeting\nid: 1\nretry: 3000\ndata: line 1\ndata: line 2\n\n:\n\ndata: bye\n\n",
            (string) $response->getBody(),
        );
    }

    public function testEventFieldsCannotInjectLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SseEvent('x', "message\ndata: injected");
    }
}
