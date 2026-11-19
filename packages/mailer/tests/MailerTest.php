<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Mailer\Address;
use Shirakaba\Mailer\Attachment;
use Shirakaba\Mailer\Email;
use Shirakaba\Mailer\EmailSent;
use Shirakaba\Mailer\LogMailer;
use Shirakaba\Mailer\MailError;
use Shirakaba\Mailer\MimeWriter;
use Shirakaba\Mailer\NullMailer;
use Shirakaba\Mailer\ObservedMailer;
use Shirakaba\Mailer\SentEmail;
use Shirakaba\Mailer\SmtpTransport;
use Shirakaba\Mailer\Testing\RecordingMailer;
use Shirakaba\Mailer\Tests\Fixtures\EventRecorder;
use Shirakaba\Mailer\Tests\Fixtures\ScriptedConnection;
use Shirakaba\Time\FrozenClock;

#[CoversClass(Address::class)]
#[CoversClass(Attachment::class)]
#[CoversClass(Email::class)]
#[CoversClass(MimeWriter::class)]
#[CoversClass(SmtpTransport::class)]
#[CoversClass(MailError::class)]
#[CoversClass(SentEmail::class)]
#[CoversClass(EmailSent::class)]
#[CoversClass(ObservedMailer::class)]
#[CoversClass(RecordingMailer::class)]
#[CoversClass(NullMailer::class)]
#[CoversClass(LogMailer::class)]
final class MailerTest extends TestCase
{
    private static function writer(): MimeWriter
    {
        return new MimeWriter(new FrozenClock(new DateTimeImmutable('2026-11-18T09:30:00+09:00')), new Randomizer(new Mt19937(1)));
    }

    /**
     * @param list<Attachment> $attachments
     */
    private static function email(string $text = "Hello\nWorld", ?string $html = null, array $attachments = []): Email
    {
        return new Email(
            from: new Address('noreply@example.com', 'Example'),
            to: [new Address('ann@example.jp', '山田 花子')],
            subject: 'ようこそ、白樺へ。登録ありがとうございます',
            text: $text,
            html: $html,
            bcc: [new Address('audit@example.com')],
            attachments: $attachments,
        );
    }

    /**
     * Decodes the RFC 2047 words of a header value.
     */
    private static function decodeWords(string $value): string
    {
        $joined = (string) preg_replace('/\?=\s+=\?/', '?==?', $value);

        return (string) preg_replace_callback('/=\?utf-8\?B\?([^?]*)\?=/', static fn (array $m): string => (string) base64_decode($m[1], true), $joined);
    }

    public function testWritesJapaneseHeadersAsEncodedWordsAndBodiesAsQuotedPrintable(): void
    {
        $message = self::writer()->write(self::email("こんにちは\n.dot line\nFrom here\n" . str_repeat('a', 100)));
        [$head, $body] = explode("\r\n\r\n", $message, 2) + ['', ''];

        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $message), 'Every line ends with CRLF.');
        self::assertStringContainsString("Date: Wed, 18 Nov 2026 00:30:00 +0000\r\n", $head);
        self::assertMatchesRegularExpression('/^Message-ID: <[0-9a-f]{32}@example\.com>\r?$/m', $head);
        self::assertStringNotContainsString('audit@example.com', $message, 'Bcc is never written.');
        $unfolded = str_replace("\r\n ", ' ', $head);
        self::assertMatchesRegularExpression('/^Subject: +=\?utf-8\?B\?/m', $unfolded);
        foreach (explode("\r\n", $head) as $line) {
            self::assertLessThanOrEqual(78, strlen($line), $line);
        }
        preg_match('/^Subject: +(.*?)\r?$/m', $unfolded, $subject);
        self::assertSame('ようこそ、白樺へ。登録ありがとうございます', self::decodeWords($subject[1] ?? ''));
        preg_match('/^To: (.*?)\r?$/m', $unfolded, $to);
        self::assertSame('山田 花子 <ann@example.jp>', self::decodeWords($to[1] ?? ''));
        self::assertStringContainsString('Content-Type: text/plain; charset=utf-8', $head);
        self::assertSame("こんにちは\n.dot line\nFrom here\n" . str_repeat('a', 100), str_replace("\r\n", "\n", quoted_printable_decode($body)));
        self::assertStringContainsString("\r\n=2Edot line\r\n=46rom here\r\n", $body, 'Leading dots and "From " are encoded.');
        foreach (explode("\r\n", $body) as $line) {
            self::assertLessThanOrEqual(76, strlen($line));
        }
    }

    public function testWritesAlternativeRelatedAndMixedParts(): void
    {
        $message = self::writer()->write(self::email('Text', '<p>HTML <img src="cid:logo@example.com"></p>', [
            new Attachment("\x89PNG", 'logo.png', 'image/png', 'logo@example.com'),
            new Attachment('a,b', '請求書.csv', 'text/csv'),
        ]));

        self::assertMatchesRegularExpression('/Content-Type: multipart\/mixed; boundary="=_[0-9a-f]+"/', $message);
        self::assertStringContainsString('Content-Type: multipart/related;', $message);
        self::assertStringContainsString('Content-Type: multipart/alternative;', $message);
        self::assertStringContainsString('Content-Type: text/html; charset=utf-8', $message);
        self::assertStringContainsString("Content-ID: <logo@example.com>\r\n", $message);
        self::assertStringContainsString('Content-Disposition: inline; filename="logo.png"', $message);
        self::assertStringContainsString(
            "Content-Disposition: attachment; filename*=utf-8''%E8%AB%8B%E6%B1%82%E6%9B%B8.csv",
            str_replace("\r\n ", ' ', $message),
            'Headers may be folded.',
        );
        self::assertStringContainsString(base64_encode('a,b'), $message);
        self::assertSame(3, preg_match_all('/^--=_[0-9a-f]+--\r?$/m', $message), 'Three multiparts close.');
    }

    public function testNoFieldCanInjectAHeader(): void
    {
        $attempts = [
            static fn () => new Address("ann@example.com\r\nBcc: x@evil.test"),
            static fn () => new Address('ann@example.com', "Ann\r\nBcc: x@evil.test"),
            static fn () => new Address('ann@exa mple.com'),
            static fn () => new Address('"quoted"@example.com'),
            static fn () => new Address('アン@example.com'),
            static fn () => new Email(new Address('a@example.com'), [new Address('b@example.com')], "Hi\r\nBcc: x@evil.test", 'x'),
            static fn () => new Email(new Address('a@example.com'), [new Address('b@example.com')], 'Hi', 'x', headers: ['X-Tag' => "a\r\nBcc: x@evil.test"]),
            static fn () => new Email(new Address('a@example.com'), [new Address('b@example.com')], 'Hi', 'x', headers: ['Bcc' => 'x@evil.test']),
            static fn () => new Email(new Address('a@example.com'), [new Address('b@example.com')], 'Hi', 'x', headers: ["X-A\r\nBcc" => 'x']),
            static fn () => new Attachment('x', "a\r\nContent-Type: text/html"),
            static fn () => new Attachment('x', 'a.txt', "text/plain\r\nX: y"),
            static fn () => new Email(new Address('a@example.com'), [], 'Hi', 'x'),
        ];
        $accepted = [];
        foreach ($attempts as $index => $attempt) {
            try {
                $attempt();
            } catch (InvalidArgumentException) {
                continue;
            }
            $accepted[] = $index;
        }
        self::assertSame([], $accepted, 'These attempts must be refused.');
    }

    public function testDisplayNamesWithSpecialCharactersAreQuoted(): void
    {
        $message = self::writer()->write(new Email(new Address('a@example.com', 'Smith, John "JJ"'), [new Address('b@example.com')], 'Hi', 'x'));

        self::assertStringContainsString('From: "Smith, John \\"JJ\\"" <a@example.com>', $message);
    }

    public function testSendsOverStartTlsWithAuthAndDotStuffing(): void
    {
        $connection = new ScriptedConnection(['220 mail.example.com ESMTP'], []);
        $transport = new SmtpTransport(
            'mail.example.com',
            587,
            'user',
            'secret',
            heloName: 'app.example.com',
            writer: self::writer(),
            connector: static fn () => $connection,
        );

        $sent = $transport->send(self::email(".hidden line\nnormal"));

        self::assertSame(
            [
                'EHLO app.example.com',
                'STARTTLS',
                'EHLO app.example.com',
                'AUTH PLAIN ' . base64_encode("\0user\0secret"),
                'MAIL FROM:<noreply@example.com>',
                'RCPT TO:<ann@example.jp>',
                'RCPT TO:<audit@example.com>',
                'DATA',
                'QUIT',
            ],
            $connection->commands(),
        );
        self::assertTrue($connection->closed);
        self::assertStringEndsWith("\r\n.\r\n", $connection->message());
        self::assertDoesNotMatchRegularExpression('/^\.[^.\r]/m', $connection->message(), 'No line starts with a single dot.');
        self::assertSame(strlen($connection->message()) - 5, $sent->size);
    }

    public function testRefusesToContinueWithoutStartTls(): void
    {
        $connection = new ScriptedConnection(['220 hi'], ['EHLO' => ['250-relay', '250 AUTH PLAIN']]);
        $transport = new SmtpTransport('relay.example.com', 587, 'user', 'secret', connector: static fn () => $connection);

        try {
            $transport->send(self::email());
            self::fail('Without STARTTLS nothing may be sent.');
        } catch (MailError $e) {
            self::assertStringContainsString('does not offer STARTTLS', $e->getMessage());
        }
        self::assertCount(1, $connection->commands(), 'Only EHLO was sent.');
        self::assertStringStartsWith('EHLO ', $connection->commands()[0]);
        self::assertTrue($connection->closed);
    }

    public function testCredentialsNeedTls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmtpTransport('localhost', 1025, 'user', 'secret', SmtpTransport::TLS_NONE);
    }

    public function testRepliesBecomeRetryableOrPermanentErrors(): void
    {
        foreach (['451 Try later' => true, '550 No such user' => false] as $reply => $retryable) {
            $connection = new ScriptedConnection(['220 hi'], ['RCPT TO:<ann@example.jp>' => [$reply]], startEncrypted: true);
            $transport = new SmtpTransport('mail.example.com', 465, tls: SmtpTransport::TLS_IMPLICIT, connector: static fn () => $connection);
            try {
                $transport->send(self::email());
                self::fail('The reply must fail the send.');
            } catch (MailError $e) {
                self::assertSame($retryable, $e->retryable, $reply);
                self::assertSame((int) substr($reply, 0, 3), $e->smtpCode);
                self::assertStringContainsString('answered ' . substr($reply, 0, 3) . ' to RCPT', $e->getMessage());
            }
        }
    }

    public function testReadsDsns(): void
    {
        SmtpTransport::fromDsn('smtps://user%40example.com:p%40ss@smtp.example.com');
        SmtpTransport::fromDsn('smtp://localhost:1025?tls=none');

        $this->expectException(InvalidArgumentException::class);
        SmtpTransport::fromDsn('http://example.com');
    }

    public function testObservedMailerReportsWithoutAddresses(): void
    {
        $recorder = new EventRecorder();
        $error = ScopedWork::run([$recorder], 'JOB', 'mail', new Cancellation(), static function (RequestScope $scope): void {
            new ObservedMailer(new RecordingMailer(), $scope)->send(self::email());
        });

        self::assertNull($error);
        self::assertCount(1, $recorder->events);
        $event = $recorder->events[0];
        self::assertInstanceOf(EmailSent::class, $event);
        self::assertSame(['mail.transport' => 'RecordingMailer', 'mail.recipients' => 2, 'mail.size' => 0], $event->attributes());
    }

    public function testRecordingMailerFindsMailAndLinks(): void
    {
        $mailer = new RecordingMailer();
        $mailer->send(self::email("Confirm: https://example.com/confirm?token=abc&x=1\nThanks"));

        $mail = $mailer->sentTo('AUDIT@example.com');
        self::assertSame(['https://example.com/confirm?token=abc&x=1'], RecordingMailer::links($mail->text));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}@example\.com$/', new NullMailer()->send(self::email())->messageId);
    }

    /**
     * Sends a real mail when SHIRAKABA_TEST_SMTP_DSN points at Mailpit (smtp://host:1025?tls=none).
     */
    public function testDeliversToMailpit(): void
    {
        $dsn = getenv('SHIRAKABA_TEST_SMTP_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set SHIRAKABA_TEST_SMTP_DSN to send through Mailpit.');
        }
        $id = bin2hex(random_bytes(6)) . '@example.com';
        $sent = SmtpTransport::fromDsn($dsn)->send(self::email('本文です', '<p>本文です</p>', [new Attachment(
            'a,b',
            '請求書.csv',
            'text/csv',
        )])->withMessageId($id));

        self::assertSame($id, $sent->messageId);
        $api = getenv('SHIRAKABA_TEST_MAILPIT_API');
        if (is_string($api) && $api !== '') {
            // A socket, because the suite runs with allow_url_fopen=0.
            $host = (string) parse_url($api, PHP_URL_HOST);
            $socket = stream_socket_client(sprintf('tcp://%s:%d', $host, (int) (parse_url($api, PHP_URL_PORT) ?? 80)), timeout: 5.0);
            self::assertIsResource($socket);
            stream_set_timeout($socket, 5);
            fwrite($socket, sprintf("GET /api/v1/search?query=%s HTTP/1.0\r\nHost: %s\r\n\r\n", rawurlencode('message-id:' . $id), $host));
            $search = stream_get_contents($socket);
            fclose($socket);
            self::assertIsString($search);
            self::assertStringContainsString('ようこそ、白樺へ', $search);
            self::assertStringContainsString('山田 花子', $search);
        }
    }
}
