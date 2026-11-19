<?php

declare(strict_types=1);

namespace Shirakaba\Console\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Console\Argument;
use Shirakaba\Console\Console;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Internal\CommandPlan;
use Shirakaba\Console\Internal\Parameter;
use Shirakaba\Console\Option;
use Shirakaba\Console\Output;
use Shirakaba\Console\Testing\ConsoleTester;
use Shirakaba\Console\Tests\Fixtures\Level;
use Shirakaba\Console\UsageError;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestCancelled;
use Shirakaba\Time\LocalDate;

#[CoversClass(ConsoleBuilder::class)]
#[CoversClass(Console::class)]
#[CoversClass(CommandPlan::class)]
#[CoversClass(Parameter::class)]
#[CoversClass(ConsoleTester::class)]
final class ConsoleTest extends TestCase
{
    private static function tester(): ConsoleTester
    {
        $cli = new ConsoleBuilder('app', 'Tools for the example.');
        $cli->command(
            'users:import',
            static function (
                Output $output,
                #[Argument('CSV file to read')] string $file,
                #[Option('Report what would change')] bool $dryRun,
                #[Option('Rows per transaction')] int $batch = 500,
                #[Option(short: 'l')] Level $level = Level::Info,
                #[Option] ?LocalDate $since = null,
                #[Option] array $tag = [],
            ): int {
                $output->line(sprintf(
                    '%s dry=%s batch=%d level=%s since=%s tags=%s',
                    $file,
                    var_export($dryRun, true),
                    $batch,
                    $level->value,
                    $since?->toString() ?? '-',
                    implode(',', array_map(strval(...), array_filter($tag, is_string(...)))),
                ));

                return $file === 'broken.csv' ? 3 : 0;
            },
            'Imports users from a CSV file.',
        );
        $cli->command('cat', static function (Output $output, #[Argument] string ...$files): void {
            $output->line(implode('|', $files));
        });
        $cli->command('fail', static function (): never {
            throw new LogicException('it broke');
        });
        $cli->command('stop', static function (Cancellation $cancellation): never {
            $cancellation->request('test');
            $cancellation->throwIfRequested();
            throw new RequestCancelled('unreachable');
        });
        $cli->command('check', static function (#[Argument] string $name): void {
            throw new UsageError(sprintf('"%s" is not a known check.', $name));
        });

        return new ConsoleTester($cli->build());
    }

    public function testParsesArgumentsAndOptions(): void
    {
        $result = self::tester()->run('users:import', 'users.csv', '--dry-run', '--batch', '100', '-l', 'debug', '--since=2026-11-01', '--tag=a', '--tag', 'b');

        self::assertSame(0, $result->exitCode, $result->errors);
        self::assertSame("users.csv dry=true batch=100 level=debug since=2026-11-01 tags=a,b\n", $result->output);
        self::assertSame("users.csv dry=false batch=500 level=info since=- tags=\n", self::tester()->run('users:import', '--no-dry-run', 'users.csv')->output);
        self::assertSame(3, self::tester()->run('users:import', 'broken.csv')->exitCode, 'The command\'s return value is the exit code.');
        self::assertSame("a|-b|c\n", self::tester()->run('cat', 'a', '--', '-b', 'c')->output);
    }

    public function testUsageErrorsExitWithTwoAndExplain(): void
    {
        foreach ([
            [['users:import'], 'Missing argument <file>.'],
            [['users:import', 'f', '--batch=many'], '--batch must be an integer.'],
            [['users:import', 'f', '--level=loud'], '--level must be one of: info, debug.'],
            [['users:import', 'f', '--verbose-ish'], 'Unknown option --verbose-ish.'],
            [['users:import', 'f', 'g'], 'Unexpected argument "g".'],
            [['users:import', 'f', '--batch'], 'Option --batch needs a value.'],
            [['nope'], 'Unknown command "nope".'],
            [['check', 'x'], '"x" is not a known check.'],
        ] as [$arguments, $message]) {
            $result = self::tester()->run(...$arguments);
            self::assertSame(2, $result->exitCode, implode(' ', $arguments));
            self::assertStringContainsString($message, $result->errors);
        }
    }

    public function testFailuresAndInterruptions(): void
    {
        $failure = self::tester()->run('fail');
        self::assertSame(1, $failure->exitCode);
        self::assertSame("LogicException: it broke\n", $failure->errors);
        self::assertStringContainsString('#0', self::tester()->run('fail', '--verbose')->errors, '--verbose adds the trace.');
        self::assertSame(130, self::tester()->run('stop')->exitCode);
    }

    public function testHelp(): void
    {
        $list = self::tester()->run('--help');
        self::assertSame(0, $list->exitCode);
        self::assertStringContainsString("Commands:\n  cat\n  check\n  fail\n  stop\n  users:import  Imports users from a CSV file.\n", $list->output);
        self::assertSame(2, self::tester()->run()->exitCode, 'No command is a usage error.');

        $help = self::tester()->run('help', 'users:import');
        self::assertSame(<<<'TEXT'
            Usage: app users:import <file> [options]

            Imports users from a CSV file.

            Arguments:
              file  CSV file to read

            Options:
              --dry-run            Report what would change
              --batch=<batch>      Rows per transaction (an integer; default: 500)
              -l, --level=<level>  (one of: info, debug; default: 'info')
              --since=<since>      (a date (YYYY-MM-DD))
              --tag=<tag>          (repeatable)

            TEXT, $help->output);
        self::assertSame($help->output, self::tester()->run('users:import', '--help')->output);
    }

    public function testDefaultCommandForSingleCommandTools(): void
    {
        $cli = new ConsoleBuilder('tool');
        $cli->command('run', static function (Output $output, #[Argument] string $input): void {
            $output->line('ran ' . $input);
        });
        $cli->default('run');
        $tester = new ConsoleTester($cli->build());

        self::assertSame("ran x\n", $tester->run('x')->output);
        self::assertStringStartsWith('Usage: tool run <input>', $tester->run('--help')->output);
    }

    public function testRejectsUnmappableCommandsWhenBuilt(): void
    {
        $accepted = [];
        foreach ([
            static fn (string $plain): int => 0,
            static fn (#[Option] \stdClass $object): int => 0,
            static fn (#[Option] bool $help): int => 0,
            static fn (#[Argument] ?string $optional = null, #[Argument] string $required = ''): int => 0,
        ] as $index => $handler) {
            $cli = new ConsoleBuilder('app');
            $cli->command('broken', $handler);
            try {
                $cli->build();
            } catch (InvalidApplication) {
                continue;
            }
            $accepted[] = $index;
        }
        self::assertSame([3], $accepted, 'Only the fourth handler can be mapped.');
    }
}
