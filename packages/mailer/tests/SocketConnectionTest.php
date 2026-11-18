<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Mailer\MailError;
use Shirakaba\Mailer\Smtp\SocketConnection;

#[CoversClass(SocketConnection::class)]
final class SocketConnectionTest extends TestCase
{
    /**
     * Whoever sits between the application and the server can append plain-text lines to the
     * STARTTLS reply. Read after the handshake, they would pass for replies of the verified
     * server, so the connection is refused instead.
     */
    public function testRefusesDataSentBeforeTlsStarts(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        $connection = new SocketConnection('127.0.0.1', $port, false, 2.0);
        $peer = stream_socket_accept($server, 2.0);
        self::assertIsResource($peer);
        fwrite($peer, "220 ready\r\n250-injected\r\n250 AUTH PLAIN\r\n");

        try {
            self::assertSame('220 ready', $connection->readLine());
            self::assertFalse($connection->isEncrypted());
            $this->expectException(MailError::class);
            $this->expectExceptionMessage('before TLS started');
            $connection->startTls();
        } finally {
            $connection->close();
            fclose($peer);
            fclose($server);
        }
    }
}
