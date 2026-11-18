<?php

declare(strict_types=1);

namespace Shirakaba\Console\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Introspection;
use Shirakaba\Console\RouteCommands;
use Shirakaba\Console\Testing\ConsoleTester;
use Shirakaba\Console\Tests\Fixtures\TaskHandlers;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;

#[CoversClass(RouteCommands::class)]
#[CoversClass(Introspection::class)]
final class RouteCommandsTest extends TestCase
{
    private static function tester(): ConsoleTester
    {
        $cli = new ConsoleBuilder('app');
        RouteCommands::register($cli, static function (): AppBuilder {
            $app = new AppBuilder();
            $handlers = new TaskHandlers();
            $app->get('/tasks/{id}', $handlers->show(...))->name('tasks.show');
            $app->get('/health', static fn (): Text => new Text('ok'))->undocumented();

            return $app;
        });

        return new ConsoleTester($cli->build());
    }

    public function testListsRoutes(): void
    {
        $result = self::tester()->run('routes');

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('GET  /tasks/{id}  tasks.show  ' . TaskHandlers::class . '::show', $result->output);
        self::assertMatchesRegularExpression('#GET  /health      -           closure at .*RouteCommandsTest\.php:\d+#', $result->output);
    }

    public function testShowsARouteByNameOrMethodAndPath(): void
    {
        $byName = self::tester()->run('routes:show', 'tasks.show');
        $byPath = self::tester()->run('routes:show', 'get /tasks/{id}');

        self::assertSame($byName->output, $byPath->output);
        self::assertSame(implode("\n", [
            'GET /tasks/{id}',
            'name: tasks.show',
            'handler: ' . TaskHandlers::class . '::show',
            'parameters:',
            '  path id: int $id (required)',
            '  query verbose: bool $verbose (optional, default false)',
            'responses:',
            '  200 text/plain string The task.',
            '  404 application/problem+json  No such task.',
        ]) . "\n", $byName->output);
    }

    public function testPrintsJson(): void
    {
        $result = self::tester()->run('routes:show', 'tasks.show', '--json');
        $route = json_decode($result->output, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($route);
        self::assertSame(['method', 'path', 'name', 'handler', 'documented', 'parameters', 'undescribedParameters', 'responses', 'returns'], array_keys($route));
        self::assertIsArray($route['parameters'] ?? null);
        self::assertIsArray($route['responses'] ?? null);
        self::assertSame(
            ['source' => 'path', 'name' => 'id', 'parameter' => 'id', 'type' => ['kind' => 'int', 'format' => null, 'nullable' => false], 'required' => true],
            $route['parameters'][0] ?? null,
        );
        self::assertSame(['status' => 404, 'contentType' => 'application/problem+json', 'type' => null, 'description' => 'No such task.'], $route['responses'][1] ?? null);

        $list = json_decode(self::tester()->run('routes', '--json')->output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list[1] ?? null);
        self::assertFalse($list[1]['documented'] ?? null);
    }

    public function testUnknownRoutesAreUsageErrors(): void
    {
        $result = self::tester()->run('routes:show', 'tasks.delete');

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('No route "tasks.delete"', $result->errors);
    }
}
