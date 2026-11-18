<?php

declare(strict_types=1);

namespace Shirakaba\MailerQueue;

use Shirakaba\Json\JsonDto;
use Shirakaba\Mailer\Attachment;

/**
 * An Attachment as it travels in a SendEmail job, its content in base64.
 */
#[JsonDto]
final readonly class QueuedAttachment
{
    public function __construct(
        public string $base64,
        public string $filename,
        public string $contentType,
        public ?string $contentId = null,
    ) {}

    public static function of(Attachment $attachment): self
    {
        return new self(base64_encode($attachment->content), $attachment->filename, $attachment->contentType, $attachment->contentId);
    }

    public function toAttachment(): Attachment
    {
        return new Attachment((string) base64_decode($this->base64, true), $this->filename, $this->contentType, $this->contentId);
    }
}
