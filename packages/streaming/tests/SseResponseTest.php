<?php

declare(strict_types=1);

namespace Shirakaba\Streaming\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Description\EventStreamType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Streaming\SseEvent;
use Shirakaba\Streaming\SseResponse;
use Shirakaba\Streaming\Tests\Fixtures\Ping;

#[CoversClass(SseResponse::class)]
#[CoversClass(SseEvent::class)]
final class SseResponseTest extends TestCase
{
    public function testDescribesEachEventsData(): void
    {
        $description = new SseResponse(['ping' => Ping::class, 'note' => 'string'])->responseDescription();

        self::assertSame('text/event-stream', $description->contentType);
        $stream = $description->body;
        self::assertInstanceOf(EventStreamType::class, $stream);
        self::assertSame(['ping', 'note'], array_keys($stream->events));
        self::assertInstanceOf(ObjectType::class, $stream->events['ping'] ?? null);
        self::assertEquals(new ScalarType('string'), $stream->events['note'] ?? null);
    }

    public function testWritesJsonData(): void
    {
        self::assertSame("event: ping\nid: 7\ndata: {\"sequence\":7}\n\n", SseEvent::json(new Ping(7), 'ping', '7')->encode());
    }

    public function testRejectsEventNamesThatWouldInjectLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SseResponse(["ping\ndata: x" => Ping::class]);
    }
}
