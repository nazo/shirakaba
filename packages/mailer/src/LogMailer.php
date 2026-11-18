<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use Psr\Log\LoggerInterface;

/**
 * Writes each mail to a PSR-3 logger instead of sending it, for development. The log holds the
 * whole message, addresses and content included, so do not use it in production.
 */
final readonly class LogMailer implements Mailer
{
    public function __construct(
        private LoggerInterface $logger,
        private MimeWriter $writer = new MimeWriter(),
    ) {}

    public function send(Email $email): SentEmail
    {
        $messageId = $this->writer->messageId($email);
        $message = $this->writer->write($email, $messageId);
        $this->logger->info("Mail not sent (LogMailer):\n{message}", ['message' => $message]);

        return new SentEmail($messageId, strlen($message));
    }
}
