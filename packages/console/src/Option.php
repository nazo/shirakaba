<?php

declare(strict_types=1);

namespace Shirakaba\Console;

use Attribute;

/**
 * A named option: `#[Option] int $batch = 500` is `--batch=1000` or `--batch 1000`. The name
 * is the parameter in kebab-case (`$dryRun` is `--dry-run`) unless given. A bool option is a
 * flag (`--dry-run`, `--no-dry-run`); an array option collects repeated values.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Option
{
    public function __construct(
        public string $description = '',
        public ?string $name = null,
        /** A one-letter alias such as "v" for -v. */
        public ?string $short = null,
    ) {}
}
