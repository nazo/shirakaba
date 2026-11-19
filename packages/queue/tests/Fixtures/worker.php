<?php

declare(strict_types=1);

// A worker process for WorkerTest: runs queue:work against the SQLite file in argv[1].
// The job sleeps, so the test can send SIGTERM while it runs.

use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Database\Database;
use Shirakaba\Queue\DatabaseQueue;
use Shirakaba\Queue\JobRunner;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\QueueCommands;
use Shirakaba\Queue\Tests\Fixtures\SendWelcome;

// The package's own vendor when tested standalone, the monorepo's otherwise.
require is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') ? dirname(__DIR__, 2) . '/vendor/autoload.php' : dirname(__DIR__, 4) . '/vendor/autoload.php';

$file = $argv[1] ?? '';
$log = $argv[2] ?? '';
$database = new Database(static fn (): PDO => new PDO('sqlite:' . $file));
$jobs = new Jobs(new DatabaseQueue($database));
$jobs->handle(
    SendWelcome::class,
    static function (SendWelcome $job) use ($log): void {
        file_put_contents($log, "start {$job->userId}\n", FILE_APPEND);
        usleep(700_000);
        file_put_contents($log, "end {$job->userId}\n", FILE_APPEND);
    },
    name: 'mail.welcome',
);

$cli = new ConsoleBuilder('worker');
QueueCommands::register($cli, static fn (): JobRunner => new JobRunner($jobs));
exit($cli->build()->run(['worker', 'queue:work', '--sleep=1']));
