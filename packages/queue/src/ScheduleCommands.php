<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Closure;
use Psr\Clock\ClockInterface;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Output;
use Shirakaba\Database\Writer;
use Shirakaba\Time\SystemClock;

/**
 * Registers the schedule commands in a console (design/10-background-work.md):
 *
 *     ScheduleCommands::register($cli, static fn (): Schedule => $schedule, static fn (): Writer => $database->connect()->writer());
 *     // crontab: * * * * * php bin/app schedule:run
 *     // app schedule:list
 */
final readonly class ScheduleCommands
{
    /**
     * @param Closure(): Schedule $schedule
     * @param Closure(): Writer $writer
     */
    public static function register(
        ConsoleBuilder $cli,
        Closure $schedule,
        Closure $writer,
        string $prefix = 'schedule:',
        ClockInterface $clock = new SystemClock(),
    ): void {
        $cli->command(
            $prefix . 'run',
            static function (Output $output) use ($schedule, $writer, $clock): int {
                foreach ($schedule()->run($writer(), $clock) as $task) {
                    $output->line('Queued ' . $task . '.');
                }

                return 0;
            },
            'Queues the jobs of the tasks due this minute; run it every minute from cron.',
        );

        $cli->command(
            $prefix . 'list',
            static function (Output $output) use ($schedule, $writer, $clock): int {
                $loaded = $schedule();
                $db = $writer();
                $now = $clock->now();
                foreach ($loaded->tasks() as $name => $cron) {
                    $next = $cron->next($now, $loaded->timezone);
                    $last = $loaded->lastRun($name, $db);
                    $output->line(sprintf(
                        '%s  %s  next %s  last %s',
                        $name,
                        $cron->expression,
                        $next?->setTimezone($loaded->timezone)->format('Y-m-d H:i T') ?? '-',
                        $last?->setTimezone($loaded->timezone)->format('Y-m-d H:i T') ?? '-',
                    ));
                }
                if ($loaded->tasks() === []) {
                    $output->line('No scheduled tasks.');
                }

                return 0;
            },
            'Lists the scheduled tasks with their next and last runs.',
        );
    }
}
