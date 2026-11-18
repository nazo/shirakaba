<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

/**
 * What sending a mail produced: its Message-ID (without angle brackets) and the size of the
 * written message in bytes (0 when the mail was only queued).
 */
final readonly class SentEmail
{
    public function __construct(
        public string $messageId,
        public int $size = 0,
    ) {}
}
