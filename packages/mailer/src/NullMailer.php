<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

/**
 * Accepts mail and sends nothing, for environments that must not send.
 */
final readonly class NullMailer implements Mailer
{
    public function __construct(
        private MimeWriter $writer = new MimeWriter(),
    ) {}

    public function send(Email $email): SentEmail
    {
        return new SentEmail($this->writer->messageId($email));
    }
}
