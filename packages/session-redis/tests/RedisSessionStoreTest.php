<?php

declare(strict_types=1);

namespace Shirakaba\SessionRedis\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Redis\RedisClient;
use Shirakaba\Session\Session;
use Shirakaba\Session\SessionMiddleware;
use Shirakaba\Session\Testing\SessionStoreChecks;
use Shirakaba\SessionRedis\RedisSessionStore;
use Shirakaba\Time\FrozenClock;

#[CoversClass(RedisSessionStore::class)]
final class RedisSessionStoreTest extends TestCase
{
    private static function redis(): RedisClient
    {
        $url = getenv('SHIRAKABA_TEST_REDIS_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set SHIRAKABA_TEST_REDIS_URL (composer test:databases) to run against Redis.');
        }

        return RedisClient::fromUrl($url);
    }

    /**
     * @param \Closure(RequestScope): void $work
     */
    private static function inScope(\Closure $work): void
    {
        $error = ScopedWork::run([], 'TEST', 'sessions', new Cancellation(), $work);
        if ($error !== null) {
            throw $error;
        }
    }

    public function testPassesTheStoreChecksAndKeepsNoIds(): void
    {
        $redis = self::redis();
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-18T00:00:00Z'));
        $prefix = 'shirakaba:test:' . bin2hex(random_bytes(4)) . ':';
        $store = new RedisSessionStore($redis, $clock, $prefix);
        self::inScope(static function (RequestScope $scope) use ($store, $clock): void {
            self::assertSame([], SessionStoreChecks::run($store, $clock, $scope));
        });

        $id = Session::newId();
        self::inScope(static fn (RequestScope $scope) => $store->save($scope, $id, ['user' => 'ann'], 120));
        self::assertNull($redis->command('GET', $prefix . $id), 'The ID itself is no key.');
        self::assertIsString($redis->command('GET', $prefix . hash('sha256', $id)));
        self::assertGreaterThan(100, $redis->command('TTL', $prefix . hash('sha256', $id)), 'Redis expires the key itself.');
        $redis->command('DEL', $prefix . hash('sha256', $id));
    }

    public function testInstancesShareSessions(): void
    {
        $redis = self::redis();
        $prefix = 'shirakaba:test:' . bin2hex(random_bytes(4)) . ':';
        $client = static function () use ($redis, $prefix): TestClient {
            $app = new AppBuilder();
            $app->middleware(new SessionMiddleware(new RedisSessionStore($redis, prefix: $prefix)));
            $app->get('/count', static function (RequestScope $scope): Text {
                $session = Session::of($scope);
                $count = $session->get('count', 0);
                $next = is_int($count) ? $count + 1 : 1;
                $session->set('count', $next);

                return new Text((string) $next);
            });

            return new TestClient($app->build());
        };
        $first = $client()->get('/count');
        preg_match('/__Host-session=([^;]+)/', $first->getHeaderLine('Set-Cookie'), $match);
        $id = $match[1] ?? self::fail('No session cookie.');

        self::assertSame('2', (string) $client()->get('/count', ['Cookie' => '__Host-session=' . $id])->getBody());
    }

    public function testAnUnreachableRedisIsA503(): void
    {
        $app = new AppBuilder();
        $app->middleware(new SessionMiddleware(new RedisSessionStore(new RedisClient('127.0.0.1', 1, timeout: 0.5))));
        $app->get('/', static fn (): Text => new Text('ok'));

        $response = new TestClient($app->build())->get('/', ['Cookie' => '__Host-session=' . Session::newId()]);
        self::assertSame(503, $response->getStatusCode());
    }
}
