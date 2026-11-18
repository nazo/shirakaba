<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use Shirakaba\Core\Scope\RequestScope;

/**
 * Reports each send to the observers of a request or job as an EmailSent event (design/12-mail-and-templates.md),
 * without addresses or content. Build it where the scope is known, such as in a job handler:
 *
 *     new ObservedMailer($mailer, $scope)->send($email);
 */
final readonly class ObservedMailer implements Mailer
{
    public function __construct(
        private Mailer $mailer,
        private RequestScope $scope,
    ) {}

    public function send(Email $email): SentEmail
    {
        $started = hrtime(true);
        $transport = (new \ReflectionClass($this->mailer))->getShortName();
        try {
            $sent = $this->mailer->send($email);
        } catch (MailError $e) {
            $this->scope->record(new EmailSent($transport, count($email->recipients()), 0, (hrtime(true) - $started) / 1e9, $e->retryable ? 'temporary' : 'permanent'));

            throw $e;
        }
        $this->scope->record(new EmailSent($transport, count($email->recipients()), $sent->size, (hrtime(true) - $started) / 1e9));

        return $sent;
    }
}
