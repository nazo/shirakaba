<?php

declare(strict_types=1);

namespace Shirakaba\MailerQueue;

use Shirakaba\Json\JsonDto;
use Shirakaba\Json\ListOf;
use Shirakaba\Json\MapOf;
use Shirakaba\Mailer\Email;

/**
 * The job QueuedMailer pushes: the whole Email as JSON, with the Message-ID fixed when it was
 * queued so a retry can be recognised as the same mail (design/12-mail-and-templates.md).
 */
#[JsonDto]
final readonly class SendEmail
{
    /**
     * @param list<QueuedAddress> $to
     * @param list<QueuedAddress> $cc
     * @param list<QueuedAddress> $bcc
     * @param list<QueuedAddress> $replyTo
     * @param list<QueuedAttachment> $attachments
     * @param array<array-key, string> $headers
     */
    public function __construct(
        public string $messageId,
        public QueuedAddress $from,
        #[ListOf(QueuedAddress::class)] public array $to,
        public string $subject,
        public string $text,
        public ?string $html = null,
        #[ListOf(QueuedAddress::class)] public array $cc = [],
        #[ListOf(QueuedAddress::class)] public array $bcc = [],
        #[ListOf(QueuedAddress::class)] public array $replyTo = [],
        #[ListOf(QueuedAttachment::class)] public array $attachments = [],
        #[MapOf('string')] public array $headers = [],
    ) {}

    public static function of(Email $email, string $messageId): self
    {
        return new self(
            $messageId,
            QueuedAddress::of($email->from),
            array_map(QueuedAddress::of(...), $email->to),
            $email->subject,
            $email->text,
            $email->html,
            array_map(QueuedAddress::of(...), $email->cc),
            array_map(QueuedAddress::of(...), $email->bcc),
            array_map(QueuedAddress::of(...), $email->replyTo),
            array_map(QueuedAttachment::of(...), $email->attachments),
            $email->headers,
        );
    }

    public function toEmail(): Email
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[(string) $name] = $value;
        }

        return new Email(
            $this->from->toAddress(),
            array_map(static fn (QueuedAddress $address) => $address->toAddress(), $this->to),
            $this->subject,
            $this->text,
            $this->html,
            array_map(static fn (QueuedAddress $address) => $address->toAddress(), $this->cc),
            array_map(static fn (QueuedAddress $address) => $address->toAddress(), $this->bcc),
            array_map(static fn (QueuedAddress $address) => $address->toAddress(), $this->replyTo),
            array_map(static fn (QueuedAttachment $attachment) => $attachment->toAttachment(), $this->attachments),
            $headers,
            $this->messageId,
        );
    }
}
