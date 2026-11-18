<?php

declare(strict_types=1);

namespace Shirakaba\Docs\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The docs ship inside vendor/shirakaba/docs, so every relative link must resolve inside the
 * package (design/13-static-analysis-and-agents.md); links out of it would break in an application.
 */
#[CoversNothing]
final class DocsLinksTest extends TestCase
{
    public function testRelativeLinksResolveInsideThePackage(): void
    {
        $package = (string) realpath(dirname(__DIR__));
        $broken = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md' || str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            $text = (string) preg_replace('/```.*?```/s', '', $text);
            preg_match_all('/\]\(([^)\s]+)\)/', $text, $matches);
            foreach ($matches[1] as $target) {
                if (preg_match('#^([a-z]+:|\#)#', $target) === 1) {
                    continue;
                }
                $path = (string) strtok($target, '#');
                $resolved = realpath($file->getPath() . '/' . rawurldecode($path));
                if ($resolved === false || !str_starts_with($resolved, $package . '/')) {
                    $broken[] = substr($file->getPathname(), strlen($package) + 1) . ' -> ' . $target;
                }
            }
        }

        self::assertSame([], $broken);
    }

    public function testTheIndexIsTheEntryPoint(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__) . '/index.md');

        self::assertStringContainsString('api.md', $index);
        self::assertStringContainsString('guide/', $index);
    }
}
