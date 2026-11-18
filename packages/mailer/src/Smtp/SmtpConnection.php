<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Smtp;

use Shirakaba\Mailer\MailError;

/**
 * The byte stream SmtpTransport talks over. SocketConnection is the real one; tests script
 * a conversation with their own.
 */
interface SmtpConnection
{
    /**
     * @throws MailError when writing fails or times out
     */
    public function write(string $data): void;

    /**
     * One line without its CRLF.
     *
     * @throws MailError when reading fails, times out or the line is too long
     */
    public function readLine(): string;

    /**
     * Upgrades the connection to TLS after STARTTLS, verifying the certificate.
     *
     * @throws MailError when the handshake fails
     */
    public function startTls(): void;

    public function isEncrypted(): bool;

    public function close(): void;
}
