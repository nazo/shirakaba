<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Docs\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Http\ProblemCode;

/**
 * Every problem code has a page in shirakaba/docs/problems, listed in its index, and every page
 * belongs to a code.
 */
#[CoversNothing]
final class ProblemPagesTest extends TestCase
{
    public function testEveryCodeHasAPageAndEveryPageACode(): void
    {
        $directory = dirname(__DIR__, 3) . '/packages/docs/problems';
        $pages = glob($directory . '/*.md');
        self::assertIsArray($pages);
        $named = array_values(array_filter(
            array_map(static fn (string $page): string => basename($page, '.md'), $pages),
            static fn (string $name): bool => $name !== 'README',
        ));
        $codes = ProblemCode::all();
        sort($named);
        sort($codes);

        self::assertSame($codes, $named);
        $index = (string) file_get_contents($directory . '/README.md');
        foreach ($codes as $code) {
            self::assertStringContainsString('[' . $code . '](' . $code . '.md)', $index);
            self::assertStringStartsWith('# ' . $code . "\n", (string) file_get_contents($directory . '/' . $code . '.md'));
        }
    }
}
