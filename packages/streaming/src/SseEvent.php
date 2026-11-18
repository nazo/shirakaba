<?php

declare(strict_types=1);

namespace Shirakaba\Streaming;

use InvalidArgumentException;

/**
 * One Server-Sent Event. Multi-line data is split into several "data:" lines; event names
 * and IDs cannot contain line breaks, which would let data inject other fields.
 */
final readonly class SseEvent
{
    /**
     * @throws InvalidArgumentException when the event name or ID contains a line break or NUL
     */
    public function __construct(
        public string $data,
        public ?string $event = null,
        public ?string $id = null,
        /** Reconnection delay in milliseconds. */
        public ?int $retry = null,
    ) {
        foreach (['event' => $event, 'id' => $id] as $field => $value) {
            if ($value !== null && preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidArgumentException(sprintf('The SSE %s must not contain line breaks or NUL.', $field));
            }
        }
    }

    /**
     * An event whose data is JSON written with shirakaba/json's rules (design/11-real-time-events.md), such as a
     * #[JsonDto] declared in #[SseResponse].
     *
     * @throws \UnexpectedValueException when the data holds an object Json refuses
     * @throws \JsonException when the data cannot be encoded
     */
    public static function json(mixed $data, ?string $event = null, ?string $id = null): self
    {
        return new self(\Shirakaba\Json\Json::encode($data), $event, $id);
    }

    public function encode(): string
    {
        $text = '';
        if ($this->event !== null) {
            $text .= 'event: ' . $this->event . "\n";
        }
        if ($this->id !== null) {
            $text .= 'id: ' . $this->id . "\n";
        }
        if ($this->retry !== null) {
            $text .= 'retry: ' . max(0, $this->retry) . "\n";
        }
        $lines = preg_split('/\r\n|\r|\n/', $this->data);
        foreach ($lines === false ? [$this->data] : $lines as $line) {
            $text .= 'data: ' . $line . "\n";
        }

        return $text . "\n";
    }
}
