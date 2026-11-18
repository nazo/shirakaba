<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use Closure;
use InvalidArgumentException;
use Shirakaba\Mailer\Smtp\SmtpConnection;
use Shirakaba\Mailer\Smtp\SocketConnection;

/**
 * Sends mail to an SMTP server (design/12-mail-and-templates.md), one connection per mail:
 *
 *     $mailer = new SmtpTransport('smtp.example.com', 587, 'user', $password);          // STARTTLS
 *     $mailer = SmtpTransport::fromDsn((string) getenv('MAILER_DSN'));                    // smtp://, smtps://
 *
 * TLS is required by default: implicit TLS (smtps, port 465) or STARTTLS, with the server's
 * certificate verified. Credentials (AUTH PLAIN, or LOGIN) are only sent over TLS. A local relay
 * without TLS, such as Mailpit in development, must be allowed explicitly with
 * `tls: SmtpTransport::TLS_NONE` (or `?tls=none` in the DSN), and then takes no credentials.
 */
final readonly class SmtpTransport implements Mailer
{
    public const string TLS_STARTTLS = 'starttls';
    public const string TLS_IMPLICIT = 'implicit';
    public const string TLS_NONE = 'none';

    private const int MAX_REPLY_LINES = 100;

    /** @var Closure(): SmtpConnection */
    private Closure $connect;

    /**
     * @param Closure(): SmtpConnection|null $connector opens the connection; tests pass a scripted one
     * @throws InvalidArgumentException for credentials without TLS or an unknown TLS mode
     */
    public function __construct(
        private string $host,
        int $port = 587,
        private ?string $username = null,
        #[\SensitiveParameter] private ?string $password = null,
        private string $tls = self::TLS_STARTTLS,
        float $timeout = 10.0,
        /** The name announced in EHLO; the machine's host name by default. */
        private ?string $heloName = null,
        private MimeWriter $writer = new MimeWriter(),
        ?Closure $connector = null,
    ) {
        if (!in_array($tls, [self::TLS_STARTTLS, self::TLS_IMPLICIT, self::TLS_NONE], true)) {
            throw new InvalidArgumentException(sprintf('Unknown TLS mode "%s".', $tls));
        }
        if ($tls === self::TLS_NONE && $username !== null) {
            throw new InvalidArgumentException('Credentials are never sent without TLS; use STARTTLS or implicit TLS.');
        }
        $this->connect = $connector ?? fn (): SmtpConnection => new SocketConnection($host, $port, $tls === self::TLS_IMPLICIT, $timeout);
    }

    /**
     * Reads `smtp://user:password@host:587`, `smtps://user:password@host:465` or
     * `smtp://localhost:1025?tls=none`. User and password are URL-decoded.
     *
     * @throws InvalidArgumentException for a DSN that is not one of these
     */
    public static function fromDsn(#[\SensitiveParameter] string $dsn): self
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['smtp', 'smtps'], true)) {
            throw new InvalidArgumentException('A mailer DSN looks like smtp://user:password@host:587 or smtps://user:password@host:465.');
        }
        parse_str($parts['query'] ?? '', $query);
        $tls = $parts['scheme'] === 'smtps' ? self::TLS_IMPLICIT : (($query['tls'] ?? null) === 'none' ? self::TLS_NONE : self::TLS_STARTTLS);

        return new self(
            $parts['host'],
            $parts['port'] ?? ($tls === self::TLS_IMPLICIT ? 465 : 587),
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            $tls,
        );
    }

    public function send(Email $email): SentEmail
    {
        $messageId = $this->writer->messageId($email);
        $message = $this->writer->write($email, $messageId);
        $connection = ($this->connect)();
        try {
            $this->expect($connection, 220);
            $extensions = $this->ehlo($connection);
            if ($this->tls === self::TLS_STARTTLS && !$connection->isEncrypted()) {
                if (!isset($extensions['STARTTLS'])) {
                    throw new MailError(sprintf('%s does not offer STARTTLS, which this transport requires.', $this->host));
                }
                $this->command($connection, 'STARTTLS', 220);
                $connection->startTls();
                $extensions = $this->ehlo($connection);
            }
            if ($this->username !== null) {
                $this->authenticate($connection, $extensions);
            }
            $this->command($connection, 'MAIL FROM:<' . $email->from->address . '>', 250);
            foreach ($email->recipients() as $recipient) {
                $this->command($connection, 'RCPT TO:<' . $recipient->address . '>', [250, 251]);
            }
            $this->command($connection, 'DATA', 354);
            // Dot-stuffing: a line starting with "." gets another one (RFC 5321 4.5.2).
            $connection->write((string) preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");
            $this->expect($connection, 250);
            try {
                $this->command($connection, 'QUIT', 221);
            } catch (MailError) {
                // The mail was accepted; a failing QUIT changes nothing.
            }
        } finally {
            $connection->close();
        }

        return new SentEmail($messageId, strlen($message));
    }

    /**
     * @return array<string, string> extension keyword => parameters
     * @throws MailError
     */
    private function ehlo(SmtpConnection $connection): array
    {
        $lines = $this->command($connection, 'EHLO ' . $this->heloName(), 250);
        $extensions = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', $line, 2);
            $extensions[strtoupper($parts[0])] = $parts[1] ?? '';
        }

        return $extensions;
    }

    /**
     * @param array<string, string> $extensions
     * @throws MailError
     */
    private function authenticate(SmtpConnection $connection, array $extensions): void
    {
        if (!$connection->isEncrypted()) {
            throw new MailError('Credentials are only sent over TLS.');
        }
        $methods = explode(' ', strtoupper($extensions['AUTH'] ?? ''));
        $username = $this->username ?? '';
        $password = $this->password ?? '';
        if (in_array('PLAIN', $methods, true)) {
            $this->command($connection, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), 235, 'AUTH PLAIN');
        } elseif (in_array('LOGIN', $methods, true)) {
            $this->command($connection, 'AUTH LOGIN', 334);
            $this->command($connection, base64_encode($username), 334, 'the user name');
            $this->command($connection, base64_encode($password), 235, 'the password');
        } else {
            throw new MailError(sprintf('%s offers no authentication this transport supports (PLAIN, LOGIN).', $this->host));
        }
    }

    /**
     * Sends one command and reads its reply.
     *
     * @param int|list<int> $expected
     * @param string|null $shown what to name the command as in errors, instead of its text
     * @return list<string> the reply's texts, one per line
     * @throws MailError
     */
    private function command(SmtpConnection $connection, string $command, int|array $expected, ?string $shown = null): array
    {
        $connection->write($command . "\r\n");

        return $this->expect($connection, $expected, $shown ?? $command);
    }

    /**
     * @param int|list<int> $expected
     * @return list<string>
     * @throws MailError
     */
    private function expect(SmtpConnection $connection, int|array $expected, ?string $after = null): array
    {
        $lines = [];
        $code = null;
        for ($i = 0; $i < self::MAX_REPLY_LINES; $i++) {
            $line = $connection->readLine();
            if (preg_match('/\A(\d{3})([ -])(.*)\z/s', $line, $match) !== 1) {
                throw new MailError(sprintf('%s sent a malformed reply.', $this->host), retryable: true);
            }
            $code = (int) $match[1];
            $lines[] = $match[3];
            if ($match[2] === ' ') {
                break;
            }
            if ($i === self::MAX_REPLY_LINES - 1) {
                throw new MailError(sprintf('%s sent a reply of more than %d lines.', $this->host, self::MAX_REPLY_LINES), retryable: true);
            }
        }
        if ($code === null || !in_array($code, (array) $expected, true)) {
            $after = $after === null ? '' : ' to ' . (str_starts_with($after, 'AUTH') || str_starts_with($after, 'the ') ? $after : strtok($after, ' '));
            throw new MailError(
                sprintf('%s answered %d%s: %s', $this->host, $code ?? 0, $after, implode(' ', $lines)),
                $code,
                $code !== null && $code >= 400 && $code < 500,
            );
        }

        return $lines;
    }

    private function heloName(): string
    {
        $host = gethostname();
        $name = $this->heloName ?? ($host === false ? 'localhost' : $host);

        return preg_match('/\A[A-Za-z0-9.-]+\z/', $name) === 1 ? $name : 'localhost';
    }
}
