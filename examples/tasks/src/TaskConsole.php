<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Console\Argument;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Option;
use Shirakaba\Console\Output;
use Shirakaba\Console\RouteCommands;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\EntityCommands;
use Shirakaba\DatabaseBridge\MigrationCommands;
use Shirakaba\Migrations\Migrations;
use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\OpenApiCommand;
use Shirakaba\Orm\Entities;

/**
 * The example's command line: migrations, the OpenAPI document, introspection (routes and
 * entities) and its own commands in one binary (design/10-background-work.md).
 */
final readonly class TaskConsole
{
    public static function builder(Database $database): ConsoleBuilder
    {
        $cli = new ConsoleBuilder('tasks', 'Commands of the example task API.');
        MigrationCommands::register($cli, static fn (): Migrations => new Migrations($database, TaskSchema::migrations(), [Task::class]), prefix: 'db:');
        OpenApiCommand::register(
            $cli,
            static fn () => TaskApi::builder($database),
            static function (): Generator {
                $generator = require dirname(__DIR__) . '/openapi.php';

                return $generator instanceof Generator ? $generator : throw new \LogicException('openapi.php must return a Generator.');
            },
        );
        RouteCommands::register($cli, static fn () => TaskApi::builder($database));
        EntityCommands::register($cli, static fn (): array => [Task::class], static fn () => $database->connect()->reader());
        $cli->command(
            'tasks:add',
            static function (
                Output $output,
                #[Argument('Title of the task')] string $title,
                #[Option('Mark it done at once')] bool $done = false,
            ) use ($database): int {
                $task = Entities::insert(new Task($title, status: $done ? TaskStatus::Done : TaskStatus::Open), $database->connect()->writer());
                $output->line(sprintf('Added task %d.', $task->id ?? 0));

                return 0;
            },
            'Adds a task.',
        );
        $cli->command(
            'tasks:list',
            static function (Output $output, #[Option('Only tasks with this status')] ?TaskStatus $status = null) use ($database): int {
                $query = Entities::query(Task::class)->orderBy('id');
                if ($status !== null) {
                    $query = $query->where('status', '=', $status);
                }
                foreach ($query->fetch($database->connect()->reader()) as $task) {
                    $output->line(sprintf('%d [%s] %s', $task->id ?? 0, $task->status === TaskStatus::Done ? 'x' : ' ', $task->title));
                }

                return 0;
            },
            'Lists tasks.',
        );

        return $cli;
    }
}
