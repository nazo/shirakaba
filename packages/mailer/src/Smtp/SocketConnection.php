<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Smtp;

use Shirakaba\Mailer\MailError;

/**
 * A TCP connection to an SMTP server, with implicit TLS or a later STARTTLS, verified
 * certificates and a timeout on connecting and on every read and write.
 */
final class SocketConnection implements SmtpConnection
{
    private const int MAX_LINE = 4096;

    /** @var resource */
    private $stream;
    private bool $encrypted;

    /**
     * @param array<string, mixed> $ssl extra stream context ssl options, such as cafile
     * @throws MailError when connecting fails
     */
    public function __construct(
        private readonly string $host,
        int $port,
        bool $implicitTls,
        float $timeout,
        array $ssl = [],
    ) {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            ...$ssl,
        ]]);
        $address = ($implicitTls ? 'tls://' : 'tcp://') . $host . ':' . $port;
        $stream = @stream_socket_client($address, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new MailError(sprintf('Unable to connect to %s:%d: %s', $host, $port, $errorMessage), retryable: true);
        }
        stream_set_timeout($stream, (int) $timeout, (int) (($timeout - floor($timeout)) * 1e6));
        $this->stream = $stream;
        $this->encrypted = $implicitTls;
    }

    public function write(string $data): void
    {
        $remaining = $data;
        while ($remaining !== '') {
            $written = @fwrite($this->stream, $remaining);
            if ($written === false || $written === 0) {
                throw new MailError(sprintf('Writing to %s failed%s.', $this->host, $this->timedOut() ? ' (timed out)' : ''), retryable: true);
            }
            $remaining = substr($remaining, $written);
        }
    }

    public function readLine(): string
    {
        $line = @fgets($this->stream, self::MAX_LINE + 2);
        if ($line === false) {
            throw new MailError(sprintf('%s closed the connection%s.', $this->host, $this->timedOut() ? ' (timed out)' : ''), retryable: true);
        }
        if (!str_ends_with($line, "\n")) {
            throw new MailError(sprintf('%s sent a reply line longer than %d bytes.', $this->host, self::MAX_LINE), retryable: true);
        }

        return rtrim($line, "\r\n");
    }

    public function startTls(): void
    {
        // Anything already read from the socket arrived before TLS, so it did not come from the
        // verified server. Left in the buffer, it would be taken for replies sent inside TLS.
        if (stream_get_meta_data($this->stream)['unread_bytes'] > 0) {
            throw new MailError(
                sprintf('%s sent data after its STARTTLS reply and before TLS started; the connection is not trusted.', $this->host),
                retryable: true,
            );
        }
        $result = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if ($result !== true) {
            throw new MailError(sprintf('The TLS handshake with %s failed; check its certificate.', $this->host), retryable: true);
        }
        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function close(): void
    {
        @fclose($this->stream);
    }

    private function timedOut(): bool
    {
        $meta = stream_get_meta_data($this->stream);

        return $meta['timed_out'];
    }
}
