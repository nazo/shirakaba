<?php

declare(strict_types=1);

namespace Shirakaba\Docs\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Each Skill has front matter agents can read: a name matching its directory and a
 * description, and ends by verifying.
 */
#[CoversNothing]
final class SkillsTest extends TestCase
{
    public function testSkillsHaveFrontMatterAndAVerifySection(): void
    {
        $directory = dirname(__DIR__) . '/skills';
        $skills = glob($directory . '/*/SKILL.md');
        self::assertIsArray($skills);
        self::assertNotSame([], $skills);
        $index = (string) file_get_contents($directory . '/README.md');
        foreach ($skills as $skill) {
            $name = basename(dirname($skill));
            $text = (string) file_get_contents($skill);
            self::assertMatchesRegularExpression('/\A---\nname: ' . preg_quote($name, '/') . '\ndescription: .{40,}\n---\n/', $text, $name);
            self::assertStringContainsString("\n## Verify\n", $text, $name);
            self::assertStringContainsString('[' . $name . '](' . $name . '/SKILL.md)', $index);
        }
    }
}
