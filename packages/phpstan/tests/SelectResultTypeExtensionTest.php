<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class SelectResultTypeExtensionTest extends TypeInferenceTestCase
{
    /**
     * @return iterable<mixed>
     */
    public static function asserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/query/selects.php');
    }

    #[DataProvider('asserts')]
    public function testFileAsserts(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }
}
