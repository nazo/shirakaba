<?php

declare(strict_types=1);

namespace Shirakaba\QueueRedis\Tests;

use ArrayObject;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\Queue\JobOutcome;
use Shirakaba\Queue\JobRunner;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\PushOptions;
use Shirakaba\Queue\RetryPolicy;
use Shirakaba\QueueRedis\RedisQueue;
use Shirakaba\QueueRedis\Tests\Fixtures\Ping;
use Shirakaba\Redis\RedisClient;
use Shirakaba\Time\FrozenClock;

#[CoversClass(RedisQueue::class)]
final class RedisQueueTest extends TestCase
{
    /**
     * @return array{RedisQueue, FrozenClock, Writer}
     */
    private static function queue(): array
    {
        $url = getenv('SHIRAKABA_TEST_REDIS_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set SHIRAKABA_TEST_REDIS_URL (composer test:databases) to run against Redis.');
        }
        $clock = new FrozenClock('2026-11-18T00:00:00Z');
        $queue = new RedisQueue(RedisClient::fromUrl($url), $clock, 'shirakaba:test:' . bin2hex(random_bytes(4)) . ':');

        return [$queue, $clock, new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect()->writer()];
    }

    public function testPushesOnlyWhatCommitsAndLeasesJobsToOneWorker(): void
    {
        [$queue, $clock, $db] = self::queue();
        try {
            $db->transaction(static function (Writer $db) use ($queue): void {
                $queue->push('ping', '{"n":0}', $db, new PushOptions());
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        $db->transaction(static fn (Writer $db) => $queue->push('ping', '{"n":1}', $db, new PushOptions()));
        $queue->push('ping', '{"n":2}', $db, new PushOptions(delay: 120));

        $first = $queue->reserve(['default'], 60);
        self::assertNotNull($first);
        self::assertSame(['ping', '{"n":1}', 1], [$first->type, $first->payload, $first->attempt]);
        self::assertNull($queue->reserve(['default'], 60), 'The delayed job is not due and the first is leased.');

        $clock->advance(61);
        $again = $queue->reserve(['default'], 60);
        self::assertNotNull($again);
        self::assertSame($first->id, $again->id, 'An expired lease hands the job to the next worker.');
        self::assertSame(2, $again->attempt);
        self::assertFalse($queue->acknowledge($first), 'The first worker lost its reservation.');
        self::assertTrue($queue->acknowledge($again));

        $clock->advance(60);
        $later = $queue->reserve(['default'], 60);
        self::assertSame('{"n":2}', $later?->payload);
    }

    public function testUniqueKeysReleasesAndFailures(): void
    {
        [$queue, $clock, $db] = self::queue();
        $queue->push('ping', '{"n":1}', $db, new PushOptions(uniqueKey: 'only-one'));
        $queue->push('ping', '{"n":2}', $db, new PushOptions(uniqueKey: 'only-one'));

        $job = $queue->reserve(['default'], 60);
        self::assertNotNull($job);
        self::assertTrue($queue->release($job, 10));
        self::assertNull($queue->reserve(['default'], 60), 'The duplicate was dropped and the released job waits.');
        $clock->advance(10);
        $job = $queue->reserve(['default'], 60);
        self::assertNotNull($job);
        self::assertTrue($queue->fail($job, 'RuntimeException: down'));

        self::assertSame([['id' => $job->id, 'queue' => 'default', 'type' => 'ping', 'attempts' => 2, 'error' => 'RuntimeException: down']], $queue->failed());
        $queue->push('ping', '{"n":3}', $db, new PushOptions(uniqueKey: 'only-one'));
        self::assertSame('{"n":3}', $queue->reserve(['default'], 60)?->payload, 'A failed job no longer blocks its key.');
    }

    public function testRunsJobsThroughTheRunner(): void
    {
        [$queue, , $db] = self::queue();
        $log = new ArrayObject();
        $jobs = new Jobs($queue);
        $jobs->handle(
            Ping::class,
            static function (Ping $ping) use ($log): void {
                $log->append($ping->n);
            },
            name: 'ping',
        );
        $jobs->push(new Ping(7), $db);

        self::assertSame(JobOutcome::Succeeded, new JobRunner($jobs, new RetryPolicy())->runNext()[1] ?? null);
        self::assertSame([7], $log->getArrayCopy());
    }
}
