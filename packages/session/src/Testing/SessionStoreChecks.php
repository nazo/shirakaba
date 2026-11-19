<?php

declare(strict_types=1);

namespace Shirakaba\Session\Testing;

use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Session\Session;
use Shirakaba\Session\SessionStore;
use Shirakaba\Time\FrozenClock;

/**
 * What every SessionStore must do, as a list of problems so any test framework can
 * assert on it. The store must read time from $clock:
 *
 *     ScopedWork::run([], 'TEST', 'store', new Cancellation(), function (RequestScope $scope) use ($clock): void {
 *         self::assertSame([], SessionStoreChecks::run(new DatabaseSessionStore($database, $clock), $clock, $scope));
 *     });
 */
final readonly class SessionStoreChecks
{
    /**
     * @return list<string> what the store got wrong; empty when it behaves
     */
    public static function run(SessionStore $store, FrozenClock $clock, RequestScope $scope): array
    {
        $problems = [];
        $expect = static function (bool $condition, string $problem) use (&$problems): void {
            if (!$condition) {
                $problems[] = $problem;
            }
        };

        $first = Session::newId();
        $second = Session::newId();
        $expect($store->load($scope, $first) === null, 'An unknown ID must load as null.');

        $data = [
            'user' => 'alice',
            'roles' => ['admin', 'editor'],
            'name' => '白樺',
            'count' => 3,
            'flag' => true,
            'nothing' => null,
            'nested' => ['a' => ['b' => 1.5]],
        ];
        $store->save($scope, $first, $data, 60);
        $expect($store->load($scope, $first) === $data, 'Saved data must load back unchanged, including nested arrays, Unicode, numbers, booleans and null.');
        $expect($store->load($scope, $second) === null, 'Sessions must not share data.');

        $store->save($scope, $second, ['user' => 'bob'], 60);
        $store->save($scope, $first, ['user' => 'carol'], 120);
        $expect($store->load($scope, $first) === ['user' => 'carol'], 'Saving again must replace the data.');
        $expect($store->load($scope, $second) === ['user' => 'bob'], 'Saving one session must not change another.');

        $clock->advance(60);
        $expect($store->load($scope, $second) === null, 'A session must expire after its lifetime.');
        $expect($store->load($scope, $first) === ['user' => 'carol'], 'Saving again must extend the lifetime.');
        $clock->advance(59);
        $expect($store->load($scope, $first) === ['user' => 'carol'], 'A session must live for its whole lifetime.');
        $clock->advance(1);
        $expect($store->load($scope, $first) === null, 'A session must expire exactly at the end of its lifetime.');

        $store->save($scope, $first, ['user' => 'dave'], 60);
        $store->delete($scope, $first);
        $expect($store->load($scope, $first) === null, 'A deleted session must be gone.');
        $store->delete($scope, $second);

        return $problems;
    }
}
