<?php

declare(strict_types=1);

namespace Shirakaba\Console;

use Shirakaba\Console\Internal\CommandPlan;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestCancelled;
use Throwable;

/**
 * A built console: parses the command line, runs one command and returns its exit code.
 *
 * Exit codes: 0 success; what the command returns; 1 for an uncaught exception (class and
 * message on standard error, the trace with --verbose); 2 for a usage error; 130 when the
 * command stops with RequestCancelled after SIGINT or SIGTERM.
 */
final readonly class Console
{
    public const int SUCCESS = 0;
    public const int FAILURE = 1;
    public const int USAGE = 2;
    public const int INTERRUPTED = 130;

    /**
     * @param array<string, CommandPlan> $commands
     * @internal created by ConsoleBuilder
     */
    public function __construct(
        private string $name,
        private string $description,
        private array $commands,
        private ?string $default,
    ) {}

    /**
     * @param list<string> $argv the command line, including the program name at index 0
     */
    public function run(array $argv, ?Output $output = null): int
    {
        $output ??= Output::standard();
        $tokens = array_slice($argv, 1);
        $verbose = in_array('--verbose', $tokens, true);
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== '--verbose'));

        $first = $tokens[0] ?? null;
        if ($first === 'help') {
            return $this->help($output, $tokens[1] ?? null);
        }
        if ($first !== null && isset($this->commands[$first])) {
            $command = $this->commands[$first];
            $tokens = array_slice($tokens, 1);
        } elseif ($this->default !== null && isset($this->commands[$this->default])) {
            $command = $this->commands[$this->default];
        } elseif ($first === null || $first === '--help' || $first === '-h') {
            return $this->help($output, null, $first === null ? self::USAGE : self::SUCCESS);
        } else {
            $output->error(sprintf("Unknown command \"%s\".\n\n%s", $first, $this->list()));

            return self::USAGE;
        }
        if (in_array('--help', $tokens, true) || in_array('-h', $tokens, true)) {
            $output->write($command->usage($this->name));

            return self::SUCCESS;
        }

        $cancellation = new Cancellation();
        $restore = self::trapSignals($cancellation);
        try {
            $arguments = $command->arguments($tokens, $output, $cancellation);
            $result = ($command->handler)(...$arguments);

            return is_int($result) ? $result : self::SUCCESS;
        } catch (UsageError $e) {
            $output->error(sprintf("%s\n\n%s", $e->getMessage(), $command->usage($this->name)));

            return self::USAGE;
        } catch (RequestCancelled) {
            return self::INTERRUPTED;
        } catch (Throwable $e) {
            $output->error(sprintf('%s: %s', $e::class, $e->getMessage()));
            if ($verbose) {
                $output->error($e->getTraceAsString());
            }

            return self::FAILURE;
        } finally {
            $restore();
        }
    }

    private function help(Output $output, ?string $command, int $status = self::SUCCESS): int
    {
        if ($command !== null) {
            $plan = $this->commands[$command] ?? null;
            if ($plan === null) {
                $output->error(sprintf("Unknown command \"%s\".\n\n%s", $command, $this->list()));

                return self::USAGE;
            }
            $output->write($plan->usage($this->name));

            return self::SUCCESS;
        }
        $text = $this->list();
        if ($status === self::SUCCESS) {
            $output->write($text);
        } else {
            $output->error(rtrim($text));
        }

        return $status;
    }

    private function list(): string
    {
        if ($this->default !== null && count($this->commands) === 1) {
            return ($this->commands[$this->default] ?? null)?->usage($this->name) ?? '';
        }
        $text = sprintf("Usage: %s <command> [arguments] [options]\n", $this->name);
        if ($this->description !== '') {
            $text .= "\n" . $this->description . "\n";
        }
        $text .= "\nCommands:\n";
        $width = max([4, ...array_map(strlen(...), array_keys($this->commands))]);
        foreach ($this->commands as $name => $plan) {
            $text .= rtrim(sprintf('  %-' . $width . 's  %s', $name, $plan->description)) . "\n";
        }

        return $text . sprintf("\nRun \"%s help <command>\" for a command's arguments and options.\n", $this->name);
    }

    /**
     * Requests the cancellation on SIGINT and SIGTERM while the command runs, so long-running
     * commands stop between units of work.
     *
     * @return \Closure(): void restores the previous handlers
     */
    private static function trapSignals(Cancellation $cancellation): \Closure
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return static function (): void {};
        }
        $async = pcntl_async_signals(true);
        $previous = [];
        foreach ([SIGINT => 'SIGINT', SIGTERM => 'SIGTERM'] as $signal => $name) {
            $previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function () use ($cancellation, $name): void {
                $cancellation->request(sprintf('Received %s.', $name));
            });
        }

        return static function () use ($previous, $async): void {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($async);
        };
    }
}
