<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Shirakaba\PHPStan\NoStateInAppScopedObjectRule;
use Shirakaba\PHPStan\PackageSource;

/**
 * @extends RuleTestCase<NoStateInAppScopedObjectRule>
 */
final class NoStateInAppScopedObjectRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoStateInAppScopedObjectRule(new PackageSource(['#/packages/[^/]+/src/#']));
    }

    public function testReportsPropertyWritesOutsideTheConstructor(): void
    {
        $message = 'Demo\StatefulMiddleware lives for the whole application; writing to its properties after construction leaks state between requests (design/03-runtimes-and-scopes.md).';

        $this->analyse([__DIR__ . '/data/packages/demo/src/StatefulMiddleware.php'], [
            [$message, 28],
            [$message, 29],
            [$message, 30],
        ]);
    }

    public function testIgnoresObjectsThatAreNotAppScoped(): void
    {
        $this->analyse([__DIR__ . '/data/packages/demo/src/SharedState.php'], []);
    }
}
