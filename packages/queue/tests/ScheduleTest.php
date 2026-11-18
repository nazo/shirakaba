<?php

declare(strict_types=1);

namespace Shirakaba\Queue\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Testing\ConsoleTester;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Database\Writer;
use Shirakaba\Migrations\Migrator;
use Shirakaba\Queue\CreateScheduleTable;
use Shirakaba\Queue\CronExpression;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\Schedule;
use Shirakaba\Queue\ScheduleCommands;
use Shirakaba\Queue\Testing\InMemoryQueue;
use Shirakaba\Queue\Tests\Fixtures\SendWelcome;
use Shirakaba\Time\FrozenClock;

#[CoversClass(CronExpression::class)]
#[CoversClass(Schedule::class)]
#[CoversClass(ScheduleCommands::class)]
#[CoversClass(CreateScheduleTable::class)]
final class ScheduleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function expressions(): iterable
    {
        yield 'every minute' => ['* * * * *', '2026-11-12T10:07:00Z', true];
        yield 'quarter hours' => ['*/15 * * * *', '2026-11-12T10:45:00Z', true];
        yield 'not a quarter' => ['*/15 * * * *', '2026-11-12T10:44:00Z', false];
        yield 'range with step' => ['0 9-17/4 * * *', '2026-11-12T13:00:00Z', true];
        yield 'list' => ['5,35 * * * *', '2026-11-12T10:35:00Z', true];
        yield 'weekday (Thursday)' => ['0 10 * * 4', '2026-11-12T10:00:00Z', true];
        yield 'Sunday as 7' => ['0 0 * * 7', '2026-11-15T00:00:00Z', true];
        yield 'day or weekday when both are set' => ['0 0 1 * 0', '2026-11-15T00:00:00Z', true];
        yield 'daily alias' => ['@daily', '2026-11-12T00:00:00Z', true];
        yield 'seconds are ignored' => ['30 10 * * *', '2026-11-12T10:30:59Z', true];
    }

    #[DataProvider('expressions')]
    public function testMatchesMinutes(string $expression, string $time, bool $matches): void
    {
        self::assertSame($matches, CronExpression::parse($expression)->matches(new DateTimeImmutable($time)));
    }

    public function testEvaluatesInTheScheduleTimeZone(): void
    {
        $sixInTokyo = CronExpression::parse('0 6 * * *');

        self::assertTrue($sixInTokyo->matches(new DateTimeImmutable('2026-11-11T21:00:00Z'), new DateTimeZone('Asia/Tokyo')));
        self::assertFalse($sixInTokyo->matches(new DateTimeImmutable('2026-11-12T06:00:00Z'), new DateTimeZone('Asia/Tokyo')));
        self::assertEquals(new DateTimeImmutable('2026-11-12T21:00:00Z'), $sixInTokyo->next(new DateTimeImmutable('2026-11-11T21:00:30Z'), new DateTimeZone('Asia/Tokyo')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'four fields' => ['* * * *'];
        yield 'minute out of range' => ['60 * * * *'];
        yield 'reversed range' => ['10-5 * * * *'];
        yield 'zero step' => ['*/0 * * * *'];
        yield 'names' => ['0 0 * * MON'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidExpressions(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);
        CronExpression::parse($expression);
    }

    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    private static function writer(Database $database): Writer
    {
        $connection = $database->connect();
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_schedule_runs');
        $connection->writer()->execute('DROP TABLE IF EXISTS shirakaba_migrations');
        new Migrator($connection, ['schedule' => new CreateScheduleTable()])->migrate();

        return $connection->writer();
    }

    #[DataProvider('databases')]
    public function testRunsEachTaskOncePerSlot(Database $database): void
    {
        $db = self::writer($database);
        $queue = new InMemoryQueue();
        $jobs = new Jobs($queue);
        $jobs->handle(SendWelcome::class, static function (): void {}, name: 'mail.welcome');
        $schedule = new Schedule($jobs, new DateTimeZone('Asia/Tokyo'))
            ->job('welcome.quarterly', '*/15 * * * *', static fn (DateTimeImmutable $slot): object => new SendWelcome((int) $slot->format('i'), 'x@example.com'))
            ->job('welcome.morning', '0 9 * * *', static fn (): object => new SendWelcome(9, 'y@example.com'));
        $clock = new FrozenClock('2026-11-12T00:00:20Z');   // 09:00 in Tokyo

        self::assertSame(['welcome.quarterly', 'welcome.morning'], $schedule->run($db, $clock));
        $clock->advance(30);
        self::assertSame([], $schedule->run($db, $clock), 'A second run in the same minute, as on another server, queues nothing.');
        $clock->advance(60);
        self::assertSame([], $schedule->run($db, $clock));
        $clock->advance(14 * 60);
        self::assertSame(['welcome.quarterly'], $schedule->run($db, $clock));
        self::assertSame(['mail.welcome', 'mail.welcome', 'mail.welcome'], $queue->pendingTypes());
        self::assertEquals(new DateTimeImmutable('2026-11-12T00:15:00Z'), $schedule->lastRun('welcome.quarterly', $db));
    }

    public function testCommandsRunAndList(): void
    {
        $db = self::writer(TestDatabases::available()['sqlite'][0] ?? self::fail('SQLite is always available.'));
        $jobs = new Jobs(new InMemoryQueue());
        $jobs->handle(SendWelcome::class, static function (): void {});
        $schedule = new Schedule($jobs)->job('welcome', '0 * * * *', static fn (): object => new SendWelcome(1, 'x@example.com'));
        $cli = new ConsoleBuilder('app');
        ScheduleCommands::register($cli, static fn (): Schedule => $schedule, static fn (): Writer => $db, clock: new FrozenClock('2026-11-12T10:00:00Z'));
        $tester = new ConsoleTester($cli->build());

        self::assertSame("Queued welcome.\n", $tester->run('schedule:run')->output);
        self::assertSame("welcome  0 * * * *  next 2026-11-12 11:00 UTC  last 2026-11-12 10:00 UTC\n", $tester->run('schedule:list')->output);
    }

    public function testRejectsDuplicateAndInvalidNames(): void
    {
        $schedule = new Schedule(new Jobs(new InMemoryQueue()))->job('a', '* * * * *', static fn (): object => new SendWelcome(1, 'x'));

        $this->expectException(InvalidArgumentException::class);
        $schedule->job('a', '* * * * *', static fn (): object => new SendWelcome(1, 'x'));
    }
}
