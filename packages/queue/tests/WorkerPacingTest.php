<?php

declare(strict_types=1);

namespace Shirakaba\Queue\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Migrations\Migrator;
use Shirakaba\Queue\CreateJobsTable;
use Shirakaba\Queue\DatabaseQueue;
use Shirakaba\Queue\JobRunner;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\PushOptions;
use Shirakaba\Queue\Testing\InMemoryQueue;
use Shirakaba\Queue\Tests\Fixtures\Prune;
use Shirakaba\Queue\Worker;

#[CoversClass(Worker::class)]
#[CoversClass(DatabaseQueue::class)]
#[CoversClass(JobRunner::class)]
final class WorkerPacingTest extends TestCase
{
    public function testRateLimitsSpaceJobsOfAQueue(): void
    {
        $jobs = new Jobs(new InMemoryQueue());
        $jobs->handle(Prune::class, static function (): void {}, name: 'prune');
        $db = new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect()->writer();
        foreach (range(1, 3) as $ignored) {
            $jobs->push(new Prune(), $db, new PushOptions('mail'));
        }

        $started = microtime(true);
        $worker = new Worker(new JobRunner($jobs), ['mail'], 60, ['mail' => 10.0]);
        self::assertSame('queue empty', $worker->run(new Cancellation(), 1, 0, PHP_INT_MAX, true));

        self::assertSame(3, $worker->ran());
        self::assertGreaterThanOrEqual(0.19, microtime(true) - $started, 'Three jobs at ten per second take at least 0.2 seconds.');
    }

    public function testRejectsNonPositiveRates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Worker(new JobRunner(new Jobs(new InMemoryQueue())), ['mail'], 60, ['mail' => 0.0]);
    }

    public function testPushWakesAnIdleWorkerOnPostgreSql(): void
    {
        $database = TestDatabases::available()['pgsql'][0] ?? null;
        if ($database === null) {
            self::markTestSkipped('Set SHIRAKABA_TEST_PGSQL_DSN to run against PostgreSQL.');
        }
        $connection = $database->connect();
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_jobs');
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_migrations');
        new Migrator($connection, ['jobs' => new CreateJobsTable()])->migrate();
        $queue = new DatabaseQueue($database);
        $jobs = new Jobs($queue);
        $jobs->handle(Prune::class, static function (): void {}, name: 'prune');
        $cancellation = new Cancellation();

        $queue->waitForJobs(['default'], 0.01, $cancellation);   // LISTEN on the worker's connection
        $jobs->push(new Prune(), $connection->writer());
        $started = microtime(true);
        $queue->waitForJobs(['default'], 5.0, $cancellation);
        self::assertLessThan(1.0, microtime(true) - $started, 'The NOTIFY ended the wait.');

        $jobs->push(new Prune(), $connection->writer(), new PushOptions('other'));
        $started = microtime(true);
        $queue->waitForJobs(['default'], 0.3, $cancellation);
        self::assertGreaterThanOrEqual(0.29, microtime(true) - $started, 'Other queues do not wake it.');
    }
}
