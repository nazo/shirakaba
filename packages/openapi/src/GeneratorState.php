<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi;

/**
 * Mutable state of one generate() call: component schemas and warnings.
 *
 * @internal
 */
final class GeneratorState
{
    /** @var array<string, array<string, mixed>> */
    public array $schemas = [];

    /** @var array<string, string> class and "input" or "output" => schema name */
    public array $componentNames = [];

    /** @var list<string> */
    public array $warnings = [];

    public bool $problemResponse = false;

    public bool $bearer = false;

    public function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }
}
