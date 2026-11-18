<?php

declare(strict_types=1);

namespace Shirakaba\Console;

use Closure;
use InvalidArgumentException;
use Shirakaba\Console\Internal\CommandPlan;
use Shirakaba\Core\InvalidApplication;

/**
 * Collects an application's commands (design/10-background-work.md):
 *
 *     $cli = new ConsoleBuilder('app');
 *     $cli->command('users:import', static function (#[Argument] string $file, #[Option] bool $dryRun, Output $output): int {
 *         ...
 *     }, 'Imports users from a CSV file.');
 *     exit($cli->build()->run($argv));
 */
final class ConsoleBuilder
{
    /** @var array<string, array{Closure, string}> */
    private array $commands = [];

    private ?string $default = null;

    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
    ) {}

    /**
     * @throws InvalidArgumentException for an invalid or duplicate name
     */
    public function command(string $name, callable $handler, string $description = ''): static
    {
        if (preg_match('/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)*$/D', $name) !== 1 || in_array($name, ['help'], true)) {
            throw new InvalidArgumentException(sprintf('Invalid command name "%s"; use lower-case words separated by ":" or "-".', $name));
        }
        if (isset($this->commands[$name])) {
            throw new InvalidArgumentException(sprintf('Command "%s" is declared twice.', $name));
        }
        $this->commands[$name] = [Closure::fromCallable($handler), $description];

        return $this;
    }

    /**
     * Runs $name when the command line does not start with a command, for tools with one
     * main command (`shirakaba-openapi app.php`).
     */
    public function default(string $name): static
    {
        $this->default = $name;

        return $this;
    }

    /**
     * Plans every command, failing on parameters that cannot come from the command line.
     *
     * @throws InvalidApplication
     */
    public function build(): Console
    {
        $plans = [];
        foreach ($this->commands as $name => [$handler, $description]) {
            $plans[$name] = CommandPlan::of($name, $description, $handler);
        }
        if ($this->default !== null && !isset($plans[$this->default])) {
            throw new InvalidApplication(sprintf('The default command "%s" is not declared.', $this->default));
        }
        ksort($plans);

        return new Console($this->name, $this->description, $plans, $this->default);
    }
}
