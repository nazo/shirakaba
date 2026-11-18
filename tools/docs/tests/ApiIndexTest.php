<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Docs\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Tools\Docs\ApiIndex;

#[CoversClass(ApiIndex::class)]
final class ApiIndexTest extends TestCase
{
    public function testTheCheckedInIndexIsCurrent(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertSame(
            ApiIndex::generate($root . '/packages'),
            (string) file_get_contents($root . '/packages/docs/api.md'),
            'packages/docs/api.md is stale; run composer docs:generate.',
        );
    }

    public function testListsPublicTypesAndSkipsInternalOnes(): void
    {
        $index = ApiIndex::generate(dirname(__DIR__, 3) . '/packages');

        self::assertStringContainsString('`Shirakaba\Console\ConsoleBuilder`', $index);
        self::assertStringContainsString('`Shirakaba\Docs\AgentCommands`', $index);
        self::assertStringNotContainsString('Shirakaba\Console\Internal\\', $index);
    }
}
