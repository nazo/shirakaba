<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shirakaba\Console\Testing\ConsoleTester;
use Shirakaba\Examples\Tasks\TaskConsole;
use Shirakaba\Examples\Tasks\TaskSchema;

#[CoversNothing]
final class TaskConsoleTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/shirakaba-tasks-console-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testMigrationsOpenApiAndOwnCommandsShareOneConsole(): void
    {
        $tester = new ConsoleTester(TaskConsole::builder(TaskSchema::database($this->file))->build());

        $list = $tester->run('--help')->output;
        foreach (['db:migrate', 'db:status', 'db:diff', 'openapi', 'tasks:add', 'tasks:list'] as $command) {
            self::assertStringContainsString('  ' . $command, $list);
        }
        self::assertSame("Added task 1.\n", $tester->run('tasks:add', 'Write docs')->output);
        self::assertSame("Added task 2.\n", $tester->run('tasks:add', 'Ship', '--done')->output);
        self::assertSame("2 [x] Ship\n", $tester->run('tasks:list', '--status=done')->output);
        self::assertSame("[x] 2026_11_11_000000_create_tasks\n", $tester->run('db:status')->output);
        self::assertSame(0, $tester->run('db:diff', '--check')->exitCode, 'The migrations match the entity.');
        self::assertStringContainsString('"title": "Tasks"', $tester->run('openapi')->output);
        self::assertSame(2, $tester->run('tasks:list', '--status=lost')->exitCode);
    }
}
