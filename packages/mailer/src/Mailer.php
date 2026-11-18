<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

/**
 * Sends mail (design/12-mail-and-templates.md). Implementations: SmtpTransport, QueuedMailer (through the queue, after
 * the transaction commits), and RecordingMailer, NullMailer and LogMailer for tests and
 * development.
 */
interface Mailer
{
    /**
     * @throws MailError when the mail cannot be sent; $retryable says whether to try later
     */
    public function send(Email $email): SentEmail;
}
