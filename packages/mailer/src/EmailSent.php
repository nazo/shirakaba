<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use Shirakaba\Core\Observe\Event;

/**
 * Reported to observers for every mail a transport tried to send (design/12-mail-and-templates.md and design/05-errors-and-observability.md), without
 * addresses, subject or content: the transport, how many recipients, the size, how long it
 * took and, on failure, the kind of error.
 */
final readonly class EmailSent implements Event
{
    public function __construct(
        public string $transport,
        public int $recipients,
        public int $size,
        public float $seconds,
        /** null when the mail was accepted; "temporary" or "permanent" otherwise. */
        public ?string $error = null,
    ) {}

    public function name(): string
    {
        return 'mail.send';
    }

    public function duration(): float
    {
        return $this->seconds;
    }

    /**
     * @return array<string, string|int>
     */
    public function attributes(): array
    {
        return [
            'mail.transport' => $this->transport,
            'mail.recipients' => $this->recipients,
            'mail.size' => $this->size,
            ...($this->error === null ? [] : ['error.type' => $this->error]),
        ];
    }
}
