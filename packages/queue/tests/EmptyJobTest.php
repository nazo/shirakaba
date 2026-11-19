<?php

declare(strict_types=1);

namespace Shirakaba\Queue\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Database;
use Shirakaba\Queue\JobRunner;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\Testing\InMemoryQueue;
use Shirakaba\Queue\Tests\Fixtures\Prune;

#[CoversNothing]
final class EmptyJobTest extends TestCase
{
    public function testAJobWithoutFieldsRuns(): void
    {
        $queue = new InMemoryQueue();
        $jobs = new Jobs($queue);
        $ran = 0;
        $jobs->handle(
            Prune::class,
            static function () use (&$ran): void {
                $ran++;
            },
            name: 'prune',
        );
        $jobs->push(new Prune(), new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect()->writer());

        self::assertSame(1, new JobRunner($jobs)->runPending());
        self::assertSame(1, $ran);
        self::assertSame([], $queue->failures(), 'The payload of a job without fields decodes.');
    }
}
