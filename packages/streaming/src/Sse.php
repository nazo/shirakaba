<?php

declare(strict_types=1);

namespace Shirakaba\Streaming;

use Generator;
use Shirakaba\Core\Description\DescribesResponse;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Http\ResponseContext;
use Shirakaba\Core\Http\ResponseValue;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A Server-Sent Events response (text/event-stream). Yield SseEvent objects, or null to
 * send a heartbeat comment that keeps proxies from closing an idle connection.
 */
final readonly class Sse implements ResponseValue, DescribesResponse
{
    /**
     * @param iterable<SseEvent|null> $events
     */
    public function __construct(
        public iterable $events,
    ) {}

    public function toResponse(ResponseContext $context): ResponseInterface
    {
        return $context->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            // Tells nginx not to buffer the stream.
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody(new GeneratorStream(self::encode($this->events)));
    }

    public static function describeResponse(): ResponseDescription
    {
        return new ResponseDescription(200, 'text/event-stream', new ScalarType('string'));
    }

    /**
     * @param iterable<mixed> $events
     * @return Generator<string>
     */
    private static function encode(iterable $events): Generator
    {
        foreach ($events as $event) {
            yield match (true) {
                $event instanceof SseEvent => $event->encode(),
                $event === null => ":\n\n",
                default => throw new RuntimeException(sprintf('Sse events must be SseEvent or null, %s given.', get_debug_type($event))),
            };
        }
    }
}
