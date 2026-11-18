<?php

declare(strict_types=1);

namespace Shirakaba\Console\Testing;

use Shirakaba\Console\Console;
use Shirakaba\Console\Output;

/**
 * Runs a console in a test and captures what it writes.
 *
 *     $result = new ConsoleTester($cli->build())->run('users:import', 'users.csv', '--dry-run');
 *     self::assertSame(0, $result->exitCode);
 */
final readonly class ConsoleTester
{
    public function __construct(
        private Console $console,
    ) {}

    public function run(string ...$arguments): ConsoleResult
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        if ($stdout === false || $stderr === false) {
            throw new \RuntimeException('Unable to open memory streams.');
        }
        $code = $this->console->run(['console', ...array_values($arguments)], new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return new ConsoleResult($code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr));
    }
}
