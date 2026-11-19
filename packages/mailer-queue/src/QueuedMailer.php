<?php

declare(strict_types=1);

namespace Shirakaba\MailerQueue;

use Shirakaba\Database\Writer;
use Shirakaba\Mailer\Email;
use Shirakaba\Mailer\Mailer;
use Shirakaba\Mailer\MailError;
use Shirakaba\Mailer\MimeWriter;
use Shirakaba\Mailer\SentEmail;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\PushOptions;

/**
 * A Mailer that queues each mail as a SendEmail job in the caller's transaction (design/12-mail-and-templates.md), so
 * the mail leaves only once the data it describes is committed, and is retried when the server
 * answers with a temporary error. Build it per request from the request's Writer, for example
 * in a `#[Db]` class:
 *
 *     final readonly class Signups
 *     {
 *         public function __construct(private Writer $db, private Jobs $jobs, private TemplatedEmail $mail) {}
 *
 *         public function signUp(...): void
 *         {
 *             $this->db->transaction(function (Writer $db) use (...): void {
 *                 ... insert the user ...
 *                 new QueuedMailer($this->jobs, $db)->send($this->mail->email('mail/welcome', $data, [$to]));
 *             });
 *         }
 *     }
 *
 * Register the job once with MailJobs::register(). The job's payload holds the whole mail until
 * it is sent (and, when it fails for good, afterwards), so a mail carrying a secret such as a
 * one-time code is better sent with `$db->afterCommit()` and the transport directly.
 */
final readonly class QueuedMailer implements Mailer
{
    /**
     * @param int $maxAttachmentBytes attachments larger than this in total are refused; produce
     *                                them inside an application job instead
     */
    public function __construct(
        private Jobs $jobs,
        private Writer $db,
        private PushOptions $options = new PushOptions(maxAttempts: 8),
        private int $maxAttachmentBytes = 2_000_000,
        private MimeWriter $writer = new MimeWriter(),
    ) {}

    /**
     * @throws MailError (not retryable) when the attachments are too large to queue
     */
    public function send(Email $email): SentEmail
    {
        $size = array_sum(array_map(static fn ($attachment): int => strlen($attachment->content), $email->attachments));
        if ($size > $this->maxAttachmentBytes) {
            throw new MailError(sprintf(
                'The attachments (%d bytes) are larger than %d bytes, too large to queue; build them in an application job.',
                $size,
                $this->maxAttachmentBytes,
            ));
        }
        $messageId = $this->writer->messageId($email);
        $this->jobs->push(SendEmail::of($email, $messageId), $this->db, $this->options);

        return new SentEmail($messageId);
    }
}
