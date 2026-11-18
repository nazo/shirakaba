<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Shirakaba\PHPStan\NoSharedMutableStateRule;
use Shirakaba\PHPStan\PackageSource;

/**
 * @extends RuleTestCase<NoSharedMutableStateRule>
 */
final class NoSharedMutableStateRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoSharedMutableStateRule(new PackageSource(['#/packages/[^/]+/src/#']));
    }

    public function testReportsStaticPropertiesGlobalsAndStaticVariables(): void
    {
        $this->analyse([__DIR__ . '/data/packages/demo/src/SharedState.php'], [
            ['Static property $counter outlives the request in long-running processes; use a constant or request scope (design/03-runtimes-and-scopes.md).', 9],
            ['The global statement shares state between requests (design/03-runtimes-and-scopes.md).', 17],
            ['Static variables outlive the request in long-running processes (design/03-runtimes-and-scopes.md).', 18],
        ]);
    }

    public function testIgnoresCodeOutsidePackageSources(): void
    {
        $this->analyse([__DIR__ . '/data/tests/OutsideSource.php'], []);
    }
}
