<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Shirakaba\PHPStan\RequiredScopeRule;

/**
 * @extends RuleTestCase<RequiredScopeRule>
 */
final class RequiredScopeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RequiredScopeRule();
    }

    public function testReportsCallsThatCannotHaveTheScope(): void
    {
        $board = 'Shirakaba\PHPStan\Tests\Scopes\Board';
        $card = 'Shirakaba\PHPStan\Tests\Scopes\Card';
        $scope = 'Shirakaba\PHPStan\Tests\Scopes\InOrganization';
        $this->analyse([__DIR__ . '/data/scopes/queries.php'], [
            [
                sprintf(
                    '%s requires the scope %s (#[RequiredScope]); pass ->scoped(new InOrganization(...)) or ->withoutScope(InOrganization::class) in the query.',
                    $board,
                    $scope,
                ),
                18,
            ],
            [
                sprintf(
                    '%s requires the scope %s (#[RequiredScope]); pass ->scoped(new InOrganization(...)) or ->withoutScope(InOrganization::class) in the query.',
                    $board,
                    $scope,
                ),
                20,
            ],
            [
                sprintf(
                    '%s requires the scope %s (#[RequiredScope]); pass it in $scopes: Entities::get(..., scopes: [new InOrganization(...)]).',
                    $board,
                    $scope,
                ),
                23,
            ],
            [
                sprintf(
                    '%s requires the scope %s (#[RequiredScope]); pass it in $scopes: Entities::delete(..., scopes: [new InOrganization(...)]).',
                    $board,
                    $scope,
                ),
                26,
            ],
            [
                sprintf(
                    '%s requires the scope %s (#[RequiredScope]); pass it in $scopes: Entities::insert(..., scopes: [new InOrganization(...)]).',
                    $board,
                    $scope,
                ),
                28,
            ],
            [
                sprintf(
                    '%s requires the scope %s (#[RequiredScope]); pass it in $scopes: Entities::load(..., scopes: [new InOrganization(...)]).',
                    $card,
                    $scope,
                ),
                30,
            ],
        ]);
    }
}
