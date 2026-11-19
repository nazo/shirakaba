<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Shirakaba\PHPStan\SafeMethodHandlerRule;

/**
 * @extends RuleTestCase<SafeMethodHandlerRule>
 */
final class SafeMethodHandlerRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SafeMethodHandlerRule();
    }

    public function testReportsSafeMethodHandlersThatTakeAWriter(): void
    {
        $message = static fn (string $route, string $via = 'a Shirakaba\Database\Writer', string $parameter = 'db'): string => sprintf(
            'The route "%s" is a safe method but its handler takes %s ($%s); take a Reader instead (design/13-static-analysis-and-agents.md).',
            $route,
            $via,
            $parameter,
        );

        $this->analyse([__DIR__ . '/data/routes/routes.php'], [
            [$message('GET /closure'),                                                                                                         15],
            [$message('GET /method'),                                                                                                          16],
            [$message('HEAD /invokable'),                                                                                                      17],
            [$message('GET /commands', 'Shirakaba\PHPStan\Tests\Fixtures\Commands, which is built from a Shirakaba\Database\Writer', 'tasks'), 20],
            [$message('GET <group root>'),                                                                                                     22],
            [$message('GET /{id} (relative to its group)'),                                                                                    23],
        ]);
    }
}
