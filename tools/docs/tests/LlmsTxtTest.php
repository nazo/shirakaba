<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Docs\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Tools\Docs\LlmsTxt;

#[CoversClass(LlmsTxt::class)]
final class LlmsTxtTest extends TestCase
{
    private static function docs(): string
    {
        return dirname(__DIR__, 3) . '/packages/docs';
    }

    public function testTheCheckedInFilesAreCurrent(): void
    {
        self::assertSame(LlmsTxt::index(self::docs()), (string) file_get_contents(self::docs() . '/llms.txt'), 'llms.txt is stale; run composer docs:generate.');
        self::assertSame(LlmsTxt::full(self::docs()), (string) file_get_contents(self::docs() . '/llms-full.txt'), 'llms-full.txt is stale; run composer docs:generate.');
    }

    public function testListsEveryChapterAndRecordAndNothingFromTheRepository(): void
    {
        $index = LlmsTxt::index(self::docs());
        $full = LlmsTxt::full(self::docs());
        $guide = glob(self::docs() . '/guide/*.md');
        $design = glob(self::docs() . '/design/*.md');
        self::assertIsArray($guide);
        self::assertIsArray($design);
        foreach ([...$guide, ...$design] as $file) {
            if (basename($file) === 'README.md') {
                continue;
            }
            $path = basename(dirname($file)) . '/' . basename($file);
            self::assertStringContainsString('](' . $path . ')', $index);
            self::assertStringContainsString('<!-- ' . $path . ' -->', $full);
        }
        self::assertStringContainsString('- [Getting started](guide/01-getting-started.md): ', $index);
        preg_match_all('/^<!-- (.+) -->$/m', $full, $sources);
        foreach ($sources[1] as $source) {
            self::assertMatchesRegularExpression('#^(index\.md|api\.md|(guide|design|messages|problems)/[^/]+\.md)$#', $source);
        }
        self::assertStringNotContainsString('roadmap.md', $index);
    }
}
