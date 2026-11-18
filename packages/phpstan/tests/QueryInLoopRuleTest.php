<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Shirakaba\PHPStan\QueryInLoopRule;

/**
 * @extends RuleTestCase<QueryInLoopRule>
 */
final class QueryInLoopRuleTest extends RuleTestCase
{
    private bool $enabled = true;

    protected function getRule(): Rule
    {
        return new QueryInLoopRule($this->enabled);
    }

    public function testReportsQueriesInLoops(): void
    {
        $message = 'A database query runs once per loop iteration (N+1); load the rows in one query with whereIn() or with().';
        $this->analyse([__DIR__ . '/data/orm/loops.php'], [
            [$message, 14],
            [$message, 15],
        ]);
    }

    public function testIsOptIn(): void
    {
        $this->enabled = false;
        $this->analyse([__DIR__ . '/data/orm/loops.php'], []);
    }
}
