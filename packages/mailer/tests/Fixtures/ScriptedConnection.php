<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Tests\Fixtures;

use Shirakaba\Mailer\MailError;
use Shirakaba\Mailer\Smtp\SmtpConnection;

/**
 * An SMTP server played from a script: each command the client writes is answered with the
 * next scripted reply, and everything written is kept for assertions.
 */
final class ScriptedConnection implements SmtpConnection
{
    /** @var list<string> */
    public array $written = [];
    public bool $closed = false;
    private bool $encrypted = false;
    /** @var list<string> */
    private array $pending;

    /**
     * @param list<string> $greeting the lines sent on connect
     * @param array<string, list<string>> $replies command prefix => reply lines, used in order
     */
    public function __construct(
        array $greeting,
        private array $replies,
        private readonly bool $startEncrypted = false,
    ) {
        $this->pending = $greeting;
        $this->encrypted = $startEncrypted;
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
        foreach ($this->replies as $prefix => $reply) {
            if (str_starts_with($data, $prefix)) {
                $this->pending = [...$this->pending, ...$reply];
                unset($this->replies[$prefix]);

                return;
            }
        }
        if (str_starts_with($data, 'EHLO')) {
            $this->pending[] = '250-mail.example.com';
            $this->pending[] = $this->encrypted ? '250 AUTH LOGIN PLAIN' : '250-STARTTLS';
            if (!$this->encrypted) {
                $this->pending[] = '250 AUTH PLAIN';
            }

            return;
        }
        $this->pending[] = match (true) {
            str_starts_with($data, 'STARTTLS') => '220 Go ahead',
            str_starts_with($data, 'AUTH PLAIN') => '235 Authenticated',
            str_starts_with($data, 'MAIL FROM'), str_starts_with($data, 'RCPT TO') => '250 OK',
            str_starts_with($data, 'DATA') => '354 Send data',
            str_ends_with($data, "\r\n.\r\n") => '250 Queued as 1',
            str_starts_with($data, 'QUIT') => '221 Bye',
            default => '500 Unexpected',
        };
    }

    public function readLine(): string
    {
        $line = array_shift($this->pending);
        if ($line === null) {
            throw new MailError('The script has no more replies.', retryable: true);
        }

        return $line;
    }

    public function startTls(): void
    {
        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * The commands written, without the message.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_values(array_map(
            static fn (string $data): string => rtrim($data, "\r\n"),
            array_filter($this->written, static fn (string $data): bool => !str_ends_with($data, "\r\n.\r\n")),
        ));
    }

    public function message(): string
    {
        foreach ($this->written as $data) {
            if (str_ends_with($data, "\r\n.\r\n")) {
                return $data;
            }
        }

        return '';
    }
}
