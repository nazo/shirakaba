<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Docs\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every Messages::see() code used in the packages has a page in shirakaba/docs, every page is
 * used and listed, and every page has at least two labelled fixes.
 */
#[CoversNothing]
final class MessagesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function usedCodes(): array
    {
        $codes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/packages', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || !str_contains($file->getPathname(), '/src/')) {
                continue;
            }
            preg_match_all("/Messages::see\\('([a-z0-9-]+)'\\)/", (string) file_get_contents($file->getPathname()), $matches);
            $codes = [...$codes, ...$matches[1]];
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * @return list<string>
     */
    private static function pages(): array
    {
        $pages = glob(dirname(__DIR__, 3) . '/packages/docs/messages/*.md');
        self::assertIsArray($pages);
        $codes = array_values(array_filter(
            array_map(static fn (string $page): string => basename($page, '.md'), $pages),
            static fn (string $code): bool => $code !== 'README',
        ));
        sort($codes);

        return $codes;
    }

    public function testEveryCodeHasAPageAndEveryPageACode(): void
    {
        self::assertNotSame([], self::usedCodes());
        self::assertSame(self::usedCodes(), self::pages());
    }

    public function testPagesAreListedAndOfferFixes(): void
    {
        $directory = dirname(__DIR__, 3) . '/packages/docs/messages';
        $index = (string) file_get_contents($directory . '/README.md');
        foreach (self::pages() as $code) {
            self::assertStringContainsString('[' . $code . '](' . $code . '.md)', $index);
            $page = (string) file_get_contents($directory . '/' . $code . '.md');
            self::assertStringStartsWith('# ' . $code . ': ', $page);
            self::assertGreaterThanOrEqual(2, preg_match_all('/^\*\*[A-Z]\. /m', $page), $code . ' needs at least two labelled fixes.');
        }
    }
}
