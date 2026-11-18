<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests\Fixtures;

/**
 * Collects strings from closures in tests.
 */
final class EventLog
{
    /** @var list<string> */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}
