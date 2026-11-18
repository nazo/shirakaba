<?php

declare(strict_types=1);

namespace Shirakaba\Console\Testing;

final readonly class ConsoleResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errors,
    ) {}
}
