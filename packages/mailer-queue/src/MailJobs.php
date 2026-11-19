<?php

declare(strict_types=1);

namespace Shirakaba\MailerQueue;

use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Mailer\Mailer;
use Shirakaba\Mailer\MailError;
use Shirakaba\Mailer\ObservedMailer;
use Shirakaba\Queue\JobFailed;
use Shirakaba\Queue\Jobs;

/**
 * Registers the handler of SendEmail jobs (design/12-mail-and-templates.md):
 *
 *     MailJobs::register($jobs, SmtpTransport::fromDsn($dsn));
 *
 * Each mail is sent with the Message-ID it was queued with and reported as an EmailSent event.
 * Temporary failures (SMTP 4xx, lost connections) are retried by the queue; permanent ones
 * (SMTP 5xx) fail the job at once.
 */
final readonly class MailJobs
{
    public static function register(Jobs $jobs, Mailer $mailer, string $name = 'mail.send'): void
    {
        $jobs->handle(
            SendEmail::class,
            static function (SendEmail $job, RequestScope $scope) use ($mailer): void {
                try {
                    new ObservedMailer($mailer, $scope)->send($job->toEmail());
                } catch (MailError $e) {
                    if ($e->retryable) {
                        throw $e;
                    }

                    throw new JobFailed($e->getMessage(), 0, $e);
                }
            },
            name: $name,
        );
    }
}
