<?php

declare(strict_types=1);

namespace Shirakaba\MailerQueue\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\Mailer\Address;
use Shirakaba\Mailer\Attachment;
use Shirakaba\Mailer\Email;
use Shirakaba\Mailer\Mailer;
use Shirakaba\Mailer\MailError;
use Shirakaba\Mailer\SentEmail;
use Shirakaba\Mailer\Testing\RecordingMailer;
use Shirakaba\MailerQueue\MailJobs;
use Shirakaba\MailerQueue\QueuedAddress;
use Shirakaba\MailerQueue\QueuedAttachment;
use Shirakaba\MailerQueue\QueuedMailer;
use Shirakaba\MailerQueue\SendEmail;
use Shirakaba\Queue\JobOutcome;
use Shirakaba\Queue\JobRunner;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\RetryPolicy;
use Shirakaba\Queue\Testing\InMemoryQueue;
use Shirakaba\Time\FrozenClock;

#[CoversClass(QueuedMailer::class)]
#[CoversClass(MailJobs::class)]
#[CoversClass(SendEmail::class)]
#[CoversClass(QueuedAddress::class)]
#[CoversClass(QueuedAttachment::class)]
final class QueuedMailerTest extends TestCase
{
    private static function writer(): Writer
    {
        return new Database(static fn (): PDO => new PDO('sqlite::memory:'))->connect()->writer();
    }

    private static function email(): Email
    {
        return new Email(
            new Address('noreply@example.com', 'Example'),
            [new Address('ann@example.jp', '山田 花子')],
            'ようこそ',
            "本文\nhttps://example.com/confirm",
            '<p>本文</p>',
            cc: [new Address('cc@example.com')],
            bcc: [new Address('audit@example.com')],
            replyTo: [new Address('help@example.com')],
            attachments: [
                new Attachment("\x00\x01binary", '請求書.pdf', 'application/pdf'),
                new Attachment('png', 'logo.png', 'image/png', 'logo@example.com'),
            ],
            headers: ['List-Unsubscribe' => '<https://example.com/unsubscribe>'],
        );
    }

    /**
     * @return array{Jobs, JobRunner, FrozenClock}
     */
    private static function queue(Mailer $mailer): array
    {
        $clock = new FrozenClock('2026-11-18T00:00:00Z');
        $jobs = new Jobs(new InMemoryQueue($clock));
        MailJobs::register($jobs, $mailer);

        return [$jobs, new JobRunner($jobs, new RetryPolicy(baseSeconds: 1, jitter: 0.0)), $clock];
    }

    public function testSendsTheWholeMailAfterTheTransactionCommits(): void
    {
        $recording = new RecordingMailer();
        [$jobs, $runner] = self::queue($recording);
        $db = self::writer();

        $queued = $db->transaction(static fn (Writer $db): SentEmail => new QueuedMailer($jobs, $db)->send(self::email()));
        $runner->runPending();

        $sent = $recording->sentTo('ann@example.jp');
        self::assertSame($queued->messageId, $sent->messageId, 'The queued Message-ID is the one sent.');
        self::assertEquals(self::email()->withMessageId($queued->messageId), $sent, 'Every field, attachments included, survives the queue.');
    }

    public function testARolledBackTransactionSendsNothing(): void
    {
        $recording = new RecordingMailer();
        [$jobs, $runner] = self::queue($recording);
        $db = self::writer();

        try {
            $db->transaction(static function (Writer $db) use ($jobs): void {
                new QueuedMailer($jobs, $db)->send(self::email());
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // expected
        }
        $runner->runPending();

        self::assertSame([], $recording->sent());
    }

    public function testRetriesTemporaryFailuresWithTheSameMessageIdAndFailsPermanentOnes(): void
    {
        $flaky = new class implements Mailer {
            /** @var list<string> */
            public array $attempts = [];

            public function send(Email $email): SentEmail
            {
                $this->attempts[] = (string) $email->messageId;
                if (count($this->attempts) === 1) {
                    throw new MailError('451 try later', 451, true);
                }
                if ($email->subject === 'refused') {
                    throw new MailError('550 no such user', 550, false);
                }

                return new SentEmail((string) $email->messageId);
            }
        };
        [$jobs, $runner, $clock] = self::queue($flaky);
        $db = self::writer();
        new QueuedMailer($jobs, $db)->send(self::email());

        $outcomes = [$runner->runNext()[1] ?? null];
        $clock->advance(1);
        $outcomes[] = $runner->runNext()[1] ?? null;
        self::assertSame([JobOutcome::Retried, JobOutcome::Succeeded], $outcomes);
        self::assertCount(2, $flaky->attempts);
        self::assertSame($flaky->attempts[0], $flaky->attempts[1], 'A retry reuses the Message-ID.');

        new QueuedMailer($jobs, $db)->send(new Email(new Address('a@example.com'), [new Address('b@example.com')], 'refused', 'x'));
        self::assertSame(JobOutcome::Failed, $runner->runNext()[1] ?? null, 'A permanent failure is not retried.');
    }

    public function testRefusesAttachmentsTooLargeToQueue(): void
    {
        [$jobs] = self::queue(new RecordingMailer());

        $this->expectException(MailError::class);
        new QueuedMailer($jobs, self::writer(), maxAttachmentBytes: 3)->send(self::email());
    }
}
