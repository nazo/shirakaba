<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Shirakaba\PHPStan\NoAmbientClockRule;

/**
 * @extends RuleTestCase<NoAmbientClockRule>
 */
final class NoAmbientClockRuleTest extends RuleTestCase
{
    /** @var list<string> */
    private array $patterns = ['#/packages/[^/]+/src/#'];

    protected function getRule(): Rule
    {
        return new NoAmbientClockRule($this->patterns);
    }

    public function testReportsWallClockReadsOutsideClocks(): void
    {
        $suffix = ' reads the wall clock; ask a Psr\Clock\ClockInterface instead.';
        $this->analyse([__DIR__ . '/data/packages/demo/src/AmbientClock.php'], [
            ['time()' . $suffix, 14],
            ['microtime()' . $suffix, 15],
            ['date()' . $suffix, 16],
            ['strtotime()' . $suffix, 17],
            ['new DateTimeImmutable()' . $suffix, 18],
            ['new DateTime()' . $suffix, 19],
            ['date_create_immutable()' . $suffix, 20],
            ['new DateTimeImmutable()' . $suffix, 21],
        ]);
    }

    public function testIsOffWithoutPaths(): void
    {
        $this->patterns = [];
        $this->analyse([__DIR__ . '/data/packages/demo/src/AmbientClock.php'], []);
    }
}
