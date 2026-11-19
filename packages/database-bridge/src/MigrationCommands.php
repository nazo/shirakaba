<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge;

use Closure;
use Psr\Clock\ClockInterface;
use Shirakaba\Console\Argument;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Option;
use Shirakaba\Console\Output;
use Shirakaba\Console\UsageError;
use Shirakaba\Migrations\Linter;
use Shirakaba\Migrations\Migrations;
use Shirakaba\Migrations\Migrator;
use Shirakaba\Orm\SchemaDiff;
use Shirakaba\Time\SystemClock;

/**
 * Registers the migration commands in a console (design/10-background-work.md), for `shirakaba-migrate` and for an
 * application's own console:
 *
 *     MigrationCommands::register($cli, static fn (): Migrations => require 'migrations.php', prefix: 'db:');
 *     // app db:migrate, app db:status, app db:rollback, app db:lint, app db:diff
 */
final readonly class MigrationCommands
{
    /**
     * @param Closure(): Migrations $migrations loaded when a command runs, so help works without it
     */
    public static function register(ConsoleBuilder $cli, Closure $migrations, string $prefix = '', ClockInterface $clock = new SystemClock()): void
    {
        $cli->command(
            $prefix . 'migrate',
            static function (Output $output, #[Option('Print the SQL instead of running it')] bool $dryRun) use ($migrations): int {
                $done = Migrator::for($migrations())->migrate($dryRun);
                if ($done === []) {
                    $output->line('Nothing to migrate.');
                }
                self::report($output, $done, $dryRun ? 'Would run' : 'Applied', $dryRun);

                return 0;
            },
            'Applies pending migrations in order.',
        );

        $cli->command(
            $prefix . 'status',
            static function (Output $output) use ($migrations): int {
                foreach (Migrator::for($migrations())->status() as $id => $applied) {
                    $output->line(sprintf('%s %s', $applied ? '[x]' : '[ ]', $id));
                }

                return 0;
            },
            'Lists migrations and whether they are applied.',
        );

        $cli->command(
            $prefix . 'rollback',
            static function (
                Output $output,
                #[Option('How many migrations to undo')] int $steps = 1,
                #[Option('Print the SQL instead of running it')] bool $dryRun = false,
            ) use ($migrations): int {
                if ($steps < 1) {
                    throw new UsageError('--steps must be at least 1.');
                }
                $done = Migrator::for($migrations())->rollback($steps, $dryRun);
                if ($done === []) {
                    $output->line('Nothing to roll back.');
                }
                self::report($output, $done, $dryRun ? 'Would run' : 'Rolled back', $dryRun);

                return 0;
            },
            'Undoes the most recent migrations.',
        );

        $cli->command(
            $prefix . 'lint',
            static function (Output $output, #[Option('Lint every migration, not only pending ones')] bool $all) use ($migrations): int {
                $loaded = $migrations();
                $connection = $loaded->database->connect();
                $status = Migrator::for($loaded)->status();
                $pending = $all
                    ? $loaded->migrations
                    : array_filter($loaded->migrations, static fn (string $id): bool => !($status[$id] ?? false), ARRAY_FILTER_USE_KEY);
                $warnings = new Linter($connection->dialect)->lint($pending);
                foreach ($warnings as $id => $messages) {
                    foreach ($messages as $message) {
                        $output->line(sprintf('%s: %s', $id, $message));
                    }
                }
                if ($warnings === []) {
                    $output->line('No warnings.');
                }

                return $warnings === [] ? 0 : 1;
            },
            'Reports risky changes in pending migrations; exits with 1 when there are warnings.',
        );

        $cli->command(
            $prefix . 'diff',
            static function (
                Output $output,
                #[Option('Class name of the generated migration')] ?string $class = null,
                #[Option('Namespace of the generated migration')] string $namespace = '',
                #[Option('Print nothing and exit with 1 when the schema lacks something')] bool $check = false,
            ) use ($migrations, $clock): int {
                $class ??= 'Migration' . $clock->now()->format('YmdHis');
                if (
                    preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $class) !== 1
                    || $namespace !== ''
                    && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1
                ) {
                    throw new UsageError('--class and --namespace must be PHP identifiers.');
                }
                $loaded = $migrations();
                if ($loaded->entities === []) {
                    throw new UsageError('diff needs the entity classes in Migrations(entities: [...]).');
                }
                // shirakaba/orm is optional, so its return type is checked rather than assumed.
                $generated = SchemaDiff::migration($loaded->entities, $loaded->database->connect()->reader(), $class, $namespace);
                $source = is_string($generated) ? $generated : null;
                if ($check) {
                    if ($source === null) {
                        $output->line('The schema matches the entities.');

                        return 0;
                    }
                    $output->error('The schema lacks tables or columns the entities need; run diff to write a migration.');

                    return 1;
                }
                $output->write($source ?? "Nothing to change.\n");

                return 0;
            },
            'Prints a migration for the tables and columns the entities need.',
        );
    }

    /**
     * @param array<string, list<string>> $done
     */
    private static function report(Output $output, array $done, string $verb, bool $dryRun): void
    {
        foreach ($done as $id => $statements) {
            $output->line(sprintf('%s %s', $verb, $id));
            if ($dryRun) {
                foreach ($statements as $statement) {
                    $output->line($statement . ';');
                }
            }
        }
    }
}
