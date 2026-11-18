<?php

declare(strict_types=1);

namespace Shirakaba\Console;

/**
 * Where a command writes: standard output for results, standard error for messages.
 */
final readonly class Output
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private mixed $stdout,
        private mixed $stderr,
    ) {}

    public static function standard(): self
    {
        return new self(STDOUT, STDERR);
    }

    /**
     * Writes to standard output as is.
     */
    public function write(string $text): void
    {
        fwrite($this->stdout, $text);
    }

    /**
     * Writes a line to standard output.
     */
    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . "\n");
    }

    /**
     * Writes a line to standard error, for warnings and progress that must not mix with
     * results a caller may pipe elsewhere.
     */
    public function error(string $text): void
    {
        fwrite($this->stderr, $text . "\n");
    }

    /**
     * Whether standard output is a terminal, for choosing between human and machine output.
     */
    public function isTerminal(): bool
    {
        return stream_isatty($this->stdout);
    }
}
