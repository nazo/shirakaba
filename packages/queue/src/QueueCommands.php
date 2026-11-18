<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Closure;
use Shirakaba\Console\Argument;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Option;
use Shirakaba\Console\Output;
use Shirakaba\Console\UsageError;
use Shirakaba\Core\Scope\Cancellation;

/**
 * Registers the queue commands in a console (design/10-background-work.md):
 *
 *     QueueCommands::register($cli, static fn (): JobRunner => $runner, static fn (): DatabaseQueue => $queue);
 *     // app queue:work, app queue:failed, app queue:retry <id>, app queue:forget <id>
 */
final readonly class QueueCommands
{
    /**
     * @param Closure(): JobRunner $runner
     * @param (Closure(): DatabaseQueue)|null $queue for the failed-job commands
     */
    public static function register(ConsoleBuilder $cli, Closure $runner, ?Closure $queue = null, string $prefix = 'queue:'): void
    {
        $cli->command($prefix . 'work', static function (
            Output $output,
            Cancellation $cancellation,
            #[Option('Queues to take jobs from, in order of priority')] array $queue = [],
            #[Option('Seconds to wait when no job is due')] int $sleep = 1,
            #[Option('Seconds a job may run before another worker may take it')] int $lease = 300,
            #[Option('Exit after this many jobs (0: no limit)')] int $maxJobs = 0,
            #[Option('Exit when memory use exceeds this many megabytes')] int $maxMemory = 256,
            #[Option('Exit when no job is due instead of waiting')] bool $stopWhenEmpty = false,
            #[Option('At most N jobs per second from a queue, as queue=N; repeatable')] array $rate = [],
        ) use ($runner): int {
            if ($sleep < 0 || $lease < 1 || $maxJobs < 0 || $maxMemory < 1) {
                throw new UsageError('--sleep, --max-jobs must not be negative; --lease and --max-memory must be positive.');
            }
            $queues = array_values(array_filter($queue, is_string(...)));
            $rates = [];
            foreach ($rate as $limit) {
                if (!is_string($limit) || preg_match('/^([a-z0-9][a-z0-9_.-]*)=(\d+(?:\.\d+)?)$/D', $limit, $match) !== 1 || (float) $match[2] <= 0) {
                    throw new UsageError('--rate takes queue=N with N jobs per second, such as --rate=mail=5.');
                }
                $rates[$match[1]] = (float) $match[2];
            }
            $work = new Worker($runner(), $queues === [] ? ['default'] : $queues, $lease, $rates);
            $reason = $work->run($cancellation, $sleep, $maxJobs, $maxMemory * 1024 * 1024, $stopWhenEmpty, $output);
            $output->error(sprintf('Worker stopped: %s (%d jobs).', $reason, $work->ran()));

            return 0;
        }, 'Runs jobs until stopped (SIGTERM stops between jobs).');

        if ($queue === null) {
            return;
        }
        $cli->command($prefix . 'failed', static function (Output $output, #[Option('How many to list')] int $limit = 50) use ($queue): int {
            $failed = $queue()->failed(max(1, $limit));
            foreach ($failed as $job) {
                $output->line(sprintf('%s  %s  %s  attempts=%d  %s', $job['id'], $job['failedAt']?->format('Y-m-d\TH:i:s\Z') ?? '-', $job['type'], $job['attempts'], $job['error']));
            }
            if ($failed === []) {
                $output->line('No failed jobs.');
            }

            return 0;
        }, 'Lists failed jobs, newest first.');
        $cli->command($prefix . 'retry', static function (Output $output, #[Argument('Failed job ID')] string $id) use ($queue): int {
            if (!$queue()->retry($id)) {
                $output->error(sprintf('No failed job %s.', $id));

                return 1;
            }
            $output->line(sprintf('Job %s will run again.', $id));

            return 0;
        }, 'Puts a failed job back in its queue.');
        $cli->command($prefix . 'forget', static function (Output $output, #[Argument('Failed job ID')] string $id) use ($queue): int {
            if (!$queue()->forget($id)) {
                $output->error(sprintf('No failed job %s.', $id));

                return 1;
            }
            $output->line(sprintf('Job %s deleted.', $id));

            return 0;
        }, 'Deletes a failed job.');
    }
}
