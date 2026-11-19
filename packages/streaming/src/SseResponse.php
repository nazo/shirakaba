<?php

declare(strict_types=1);

namespace Shirakaba\Streaming;

use Attribute;
use InvalidArgumentException;
use Shirakaba\Core\Description\EventStreamType;
use Shirakaba\Core\Description\ResponseDeclaration;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Json\JsonResponse;

/**
 * Declares the events of a handler's Sse response (design/11-real-time-events.md), so API descriptions name each
 * event and the type of its data:
 *
 *     #[SseResponse(['note' => NoteView::class, 'noteDeleted' => NoteDeleted::class])]
 *     public function stream(): Sse { ... yield SseEvent::json($view, 'note'); ... }
 *
 * The data of an event is a #[JsonDto] or #[JsonUnion] class written with SseEvent::json(),
 * or "string" for plain text.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final readonly class SseResponse implements ResponseDeclaration
{
    /**
     * @param non-empty-array<string, class-string|'string'> $events event name => data class, or "string"
     * @throws InvalidArgumentException when an event name is empty or contains a line break
     */
    public function __construct(
        public array $events,
        public string $description = '',
    ) {
        foreach (array_keys($events) as $name) {
            if ($name === '' || preg_match('/[\r\n\0]/', (string) $name) === 1) {
                throw new InvalidArgumentException('SSE event names must be non-empty and contain no line breaks or NUL.');
            }
        }
    }

    public function responseDescription(): ResponseDescription
    {
        $events = [];
        foreach ($this->events as $name => $type) {
            $events[(string) $name] = $type === 'string'
                ? new ScalarType('string')
                : new JsonResponse(200, $type)->responseDescription()->body ?? new ScalarType('string');
        }

        return new ResponseDescription(200, 'text/event-stream', new EventStreamType($events), $this->description);
    }
}
