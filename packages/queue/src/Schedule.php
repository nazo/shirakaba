<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Shirakaba\Database\Error\UniqueViolation;
use Shirakaba\Database\Writer;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Select;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Recurring work as jobs (design/10-background-work.md). The schedule is PHP code; system cron runs
 * `schedule:run` every minute on any number of servers, and each due task pushes its job
 * once per minute slot:
 *
 *     $schedule = new Schedule($jobs, new DateTimeZone('Asia/Tokyo'))
 *         ->job('sessions.gc', '*\/15 * * * *', static fn (): object => new CollectSessions())
 *         ->job('reports.daily', '0 6 * * *', static fn (DateTimeImmutable $slot): object => new DailyReport($slot));
 *
 * A slot whose minute passed while nothing ran (the servers were down) is not caught up.
 */
final class Schedule
{
    /** @var array<string, array{CronExpression, Closure(DateTimeImmutable): object, PushOptions}> */
    private array $tasks = [];

    public function __construct(
        private readonly Jobs $jobs,
        public readonly DateTimeZone $timezone = new DateTimeZone('UTC'),
        private readonly string $table = 'shirakaba_schedule_runs',
    ) {}

    /**
     * @param string $name a stable name: it identifies the task's runs in the database
     * @param Closure(DateTimeImmutable): object $job builds the job for a slot (the minute, in UTC)
     * @throws InvalidArgumentException for an invalid name or cron expression, or a name used twice
     */
    public function job(string $name, string $cron, Closure $job, PushOptions $options = new PushOptions()): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,99}$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid scheduled task name "%s".', $name));
        }
        if (isset($this->tasks[$name])) {
            throw new InvalidArgumentException(sprintf('The scheduled task %s is defined twice.', $name));
        }
        $this->tasks[$name] = [CronExpression::parse($cron), $job, $options];

        return $this;
    }

    /**
     * @return array<string, CronExpression> task name => expression
     */
    public function tasks(): array
    {
        return array_map(static fn (array $task): CronExpression => $task[0], $this->tasks);
    }

    /**
     * Pushes the job of every task due in the current minute that has not run for it yet.
     * Each task is claimed and pushed in one transaction, so a failure leaves it unclaimed.
     *
     * @return list<string> the tasks pushed
     * @throws \Shirakaba\Database\Error\DatabaseError
     */
    public function run(Writer $db, ClockInterface $clock = new SystemClock()): array
    {
        $now = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $slot = $now->setTime((int) $now->format('G'), (int) $now->format('i'));
        $pushed = [];
        foreach ($this->tasks as $name => [$cron, $make, $options]) {
            if (!$cron->matches($slot, $this->timezone)) {
                continue;
            }
            $claimed = $db->transaction(function (Writer $db) use ($name, $slot, $now, $make, $options): bool {
                try {
                    Insert::into($this->table)->values(['task' => $name, 'slot' => $slot, 'created_at' => $now])->execute($db);
                } catch (UniqueViolation) {
                    return false;
                }
                $this->jobs->push($make($slot), $db, $options);

                return true;
            });
            if ($claimed) {
                $pushed[] = $name;
            }
        }

        return $pushed;
    }

    /**
     * When a task last ran, from the runs table.
     *
     * @throws \Shirakaba\Database\Error\DatabaseError
     */
    public function lastRun(string $name, Writer $db): ?DateTimeImmutable
    {
        $value = Select::from($this->table)->columns('slot')->where('task', '=', $name)->orderBy('slot', 'desc')->limit(1)->fetchValue($db);

        return is_string($value) ? $db->dialect()->parseInstant($value) : null;
    }
}
