<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use InvalidArgumentException;

/**
 * A mail to send (design/12-mail-and-templates.md): immutable, checked when built, so no field can inject a header.
 *
 *     $email = new Email(
 *         from: new Address('noreply@example.com', 'Example'),
 *         to: [new Address($user->email, $user->name)],
 *         subject: 'ようこそ',
 *         text: $text,
 *         html: $html,
 *     );
 *
 * Bcc recipients receive the mail but are never written into it.
 */
final readonly class Email
{
    /** @var list<string> Headers the writer sets itself, which extra headers cannot replace. */
    private const array RESERVED = ['from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'sender', 'return-path'];

    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<Address> $replyTo
     * @param list<Attachment> $attachments
     * @param array<string, string> $headers extra headers, such as List-Unsubscribe
     * @param string|null $messageId the Message-ID to send with, without angle brackets; one
     *                               is generated when null (a retried mail keeps its own)
     * @throws InvalidArgumentException for a missing recipient, a subject with a line break or
     *                                  a header that is invalid or set by the writer
     */
    public function __construct(
        public Address $from,
        public array $to,
        public string $subject,
        public string $text,
        public ?string $html = null,
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public array $attachments = [],
        public array $headers = [],
        public ?string $messageId = null,
    ) {
        if ($to === [] && $cc === [] && $bcc === []) {
            throw new InvalidArgumentException('A mail needs at least one recipient.');
        }
        if (preg_match('/[\r\n\0]/', $subject) === 1) {
            throw new InvalidArgumentException('A subject cannot contain line breaks or NUL.');
        }
        foreach ($headers as $name => $value) {
            if (preg_match('/\A[A-Za-z0-9-]+\z/', $name) !== 1 || in_array(strtolower($name), self::RESERVED, true)) {
                throw new InvalidArgumentException(sprintf('"%s" cannot be set as an extra header.', $name));
            }
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidArgumentException(sprintf('The value of "%s" cannot contain line breaks or NUL.', $name));
            }
        }
        if ($messageId !== null && preg_match('/\A[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+@[A-Za-z0-9.-]+\z/', $messageId) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a Message-ID.', $messageId));
        }
    }

    /**
     * The same mail with a fixed Message-ID, so a retry can be recognised as a duplicate.
     */
    public function withMessageId(string $messageId): self
    {
        return new self($this->from, $this->to, $this->subject, $this->text, $this->html, $this->cc, $this->bcc, $this->replyTo, $this->attachments, $this->headers, $messageId);
    }

    /**
     * Every recipient, for the SMTP envelope.
     *
     * @return list<Address>
     */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }
}
