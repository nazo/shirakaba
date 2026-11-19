<?php

declare(strict_types=1);

namespace Shirakaba\Queue\Tests;

use ArrayObject;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Database\Writer;
use Shirakaba\Migrations\Migrator;
use Shirakaba\Query\Insert;
use Shirakaba\Queue\CreateJobsTable;
use Shirakaba\Queue\DatabaseQueue;
use Shirakaba\Queue\JobFailed;
use Shirakaba\Queue\JobOutcome;
use Shirakaba\Queue\JobRunner;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\PushOptions;
use Shirakaba\Queue\RetryPolicy;
use Shirakaba\Queue\Testing\InMemoryQueue;
use Shirakaba\Queue\Tests\Fixtures\SendWelcome;
use Shirakaba\Time\FrozenClock;

#[CoversClass(DatabaseQueue::class)]
#[CoversClass(Jobs::class)]
#[CoversClass(JobRunner::class)]
#[CoversClass(RetryPolicy::class)]
#[CoversClass(CreateJobsTable::class)]
#[CoversClass(InMemoryQueue::class)]
final class QueueTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        $databases = TestDatabases::available();
        // Workers need their own connections to the same database; an in-memory SQLite
        // database is private to one connection.
        $file = sys_get_temp_dir() . '/shirakaba-queue-' . getmypid() . '.sqlite';
        @unlink($file);
        $databases['sqlite'] = [new Database(static fn (): \PDO => new \PDO('sqlite:' . $file))];

        return $databases;
    }

    /**
     * @return array{Writer, FrozenClock}
     */
    private static function setUpQueue(Database $database): array
    {
        $connection = $database->connect();
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_jobs');
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_migrations');
        new Migrator($connection, ['jobs' => new CreateJobsTable()])->migrate();

        return [$connection->writer(), new FrozenClock('2026-11-12T00:00:00Z')];
    }

    /**
     * @param ArrayObject<int, string> $log
     */
    private static function jobs(DatabaseQueue|InMemoryQueue $queue, ArrayObject $log, int $failUntil = 0): Jobs
    {
        $jobs = new Jobs($queue);
        $jobs->handle(
            SendWelcome::class,
            static function (SendWelcome $job) use ($log, $failUntil): void {
                $log->append(sprintf('mail %d', $job->userId));
                if (count($log) <= $failUntil) {
                    throw new RuntimeException('SMTP is down');
                }
            },
            name: 'mail.welcome',
        );

        return $jobs;
    }

    #[DataProvider('databases')]
    public function testJobsFollowTheirTransaction(Database $database): void
    {
        [$db, $clock] = self::setUpQueue($database);
        $log = new ArrayObject();
        $jobs = self::jobs(new DatabaseQueue($database, $clock), $log);

        try {
            $db->transaction(static function (Writer $db) use ($jobs): void {
                $jobs->push(new SendWelcome(1, 'a@example.com'), $db);

                throw new LogicException('rolled back');
            });
        } catch (LogicException) {
        }
        $db->transaction(static fn (Writer $db) => $jobs->push(new SendWelcome(2, 'b@example.com'), $db));

        self::assertSame(1, new JobRunner($jobs)->runPending());
        self::assertSame(['mail 2'], $log->getArrayCopy(), 'The rolled-back job was never delivered.');
    }

    #[DataProvider('databases')]
    public function testLeasesKeepWorkersApartAndExpire(Database $database): void
    {
        [$db, $clock] = self::setUpQueue($database);
        $first = new DatabaseQueue($database, $clock);
        $second = new DatabaseQueue($database, $clock);
        $jobs = self::jobs($first, new ArrayObject());
        $jobs->push(new SendWelcome(1, 'a@example.com'), $db);
        $jobs->push(new SendWelcome(2, 'b@example.com'), $db);

        $a = $first->reserve(['default'], 60);
        $b = $second->reserve(['default'], 60);
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame($a->id, $b->id, 'Two workers took different jobs.');
        self::assertNull($second->reserve(['default'], 60), 'Both jobs are held.');

        $clock->advance(61);
        $again = $second->reserve(['default'], 60);
        self::assertNotNull($again, 'An expired lease frees the job.');
        self::assertSame(2, $again->attempt);
        self::assertFalse($first->acknowledge($again->id === $a->id ? $a : $b), 'The first worker lost its reservation.');
        self::assertTrue($second->acknowledge($again));
    }

    #[DataProvider('databases')]
    public function testRetriesWithBackoffThenFails(Database $database): void
    {
        [$db, $clock] = self::setUpQueue($database);
        $queue = new DatabaseQueue($database, $clock);
        $log = new ArrayObject();
        $jobs = self::jobs($queue, $log, failUntil: 10);
        $runner = new JobRunner($jobs, new RetryPolicy(baseSeconds: 10, jitter: 0.0));
        $jobs->push(new SendWelcome(7, 'c@example.com'), $db, new PushOptions(maxAttempts: 3));

        self::assertSame(JobOutcome::Retried, $runner->runNext()[1] ?? null);
        self::assertNull($runner->runNext(), 'The retry waits for its backoff.');
        $clock->advance(10);
        self::assertSame(JobOutcome::Retried, $runner->runNext()[1] ?? null);
        $clock->advance(19);
        self::assertNull($runner->runNext(), 'The second backoff is 20 seconds.');
        $clock->advance(1);
        self::assertSame(JobOutcome::Failed, $runner->runNext()[1] ?? null);

        $failed = $queue->failed();
        self::assertCount(1, $failed);
        $first = $failed[0];
        self::assertSame('RuntimeException: SMTP is down', $first['error']);
        self::assertSame(3, $first['attempts']);
        self::assertTrue($queue->retry($first['id']));
        $again = $runner->runNext();
        self::assertSame(JobOutcome::Retried, $again[1] ?? null, 'A retried failure runs again.');
    }

    public function testJobFailedIsNotRetried(): void
    {
        $queue = new InMemoryQueue();
        $jobs = new Jobs($queue);
        $jobs->handle(
            SendWelcome::class,
            static function (): void {
                throw new JobFailed('The address does not exist.');
            },
            name: 'mail.welcome',
        );
        $runner = new JobRunner($jobs);
        $jobs->push(
            new SendWelcome(7, 'nobody@example.com'),
            new Database(static fn (): \PDO => new \PDO('sqlite::memory:'))->connect()->writer(),
            new PushOptions(maxAttempts: 5),
        );

        self::assertSame(JobOutcome::Failed, $runner->runNext()[1] ?? null);
        self::assertNull($runner->runNext(), 'Nothing is left to retry.');
    }

    #[DataProvider('databases')]
    public function testUniqueKeysAndUnknownTypes(Database $database): void
    {
        [$db, $clock] = self::setUpQueue($database);
        $queue = new DatabaseQueue($database, $clock);
        $log = new ArrayObject();
        $jobs = self::jobs($queue, $log);
        $options = new PushOptions(uniqueKey: 'welcome-1');

        self::assertTrue($jobs->push(new SendWelcome(1, 'a@example.com'), $db, $options));
        self::assertFalse($jobs->push(new SendWelcome(1, 'a@example.com'), $db, $options), 'A waiting job with the key exists.');
        new JobRunner($jobs)->runPending();
        self::assertTrue($jobs->push(new SendWelcome(1, 'a@example.com'), $db, $options), 'The key is free once the job ran.');

        Insert::into('shirakaba_jobs')
            ->values([
                'queue' => 'default',
                'type' => 'evil.code',
                'payload' => '{}',
                'attempts' => 0,
                'max_attempts' => 5,
                'available_at' => $clock->now(),
                'created_at' => $clock->now(),
            ])
            ->execute($db);
        $runner = new JobRunner($jobs);
        $outcomes = [];
        while (($result = $runner->runNext()) !== null) {
            $outcomes[$result[0]->type] = $result[1];
        }
        self::assertSame(JobOutcome::Failed, $outcomes['evil.code'] ?? null, 'An unknown type is failed, never run.');
        self::assertSame(['mail 1', 'mail 1'], $log->getArrayCopy());
        self::assertSame('Unknown job type "evil.code".', $queue->failed()[0]['error'] ?? null);
        self::assertTrue($queue->forget($queue->failed()[0]['id'] ?? ''));
        self::assertSame([], $queue->failed());
    }

    public function testInMemoryQueueWaitsForCommit(): void
    {
        $db = new Database(static fn (): \PDO => new \PDO('sqlite::memory:'))->connect()->writer();
        $queue = new InMemoryQueue();
        $log = new ArrayObject();
        $jobs = self::jobs($queue, $log);
        try {
            $db->transaction(static function (Writer $db) use ($jobs): void {
                $jobs->push(new SendWelcome(1, 'a@example.com'), $db);

                throw new LogicException('rolled back');
            });
        } catch (LogicException) {
        }
        $jobs->push(new SendWelcome(2, 'b@example.com'), $db);

        self::assertSame(['mail.welcome'], $queue->pendingTypes());
        self::assertSame(1, new JobRunner($jobs)->runPending());
        self::assertSame(['mail 2'], $log->getArrayCopy());
    }

    public function testRejectsJobsThatAreNotJsonDtos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Jobs(new InMemoryQueue())->handle(\stdClass::class, static function (): void {});
    }
}
