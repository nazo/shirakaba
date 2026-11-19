<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Auth\Authentication;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\ResponseJson;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\RateLimit\InMemoryRateLimitStore;
use Shirakaba\RateLimit\RateKey;
use Shirakaba\RateLimit\RateLimiter;
use Shirakaba\RateLimit\RateLimitMiddleware;
use Shirakaba\RateLimit\RateLimitResult;
use Shirakaba\RateLimit\TooManyRequests;
use Shirakaba\Time\FrozenClock;

#[CoversClass(RateLimiter::class)]
#[CoversClass(RateLimitMiddleware::class)]
#[CoversClass(RateLimitResult::class)]
#[CoversClass(RateKey::class)]
#[CoversClass(TooManyRequests::class)]
#[CoversClass(InMemoryRateLimitStore::class)]
final class RateLimiterTest extends TestCase
{
    private static function app(RateLimiter $limiter): AppBuilder
    {
        $app = new AppBuilder();
        $app->post('/signin', static fn (): Text => new Text('ok'))->middleware($limiter->limit('sign-in', 2, 60, RateKey::address()));
        $app->post('/posts', static fn (): Text => new Text('ok'))
            ->middleware(
                new class implements MiddlewareInterface {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        if ($request->getHeaderLine('X-User') !== '') {
                            RequestScope::of($request)->set(new Authentication((object) ['id' => $request->getHeaderLine('X-User')]));
                        }

                        return $handler->handle($request);
                    }
                },
                $limiter->limit('posting', 1, 10, RateKey::subject(static fn (object $user): string => property_exists($user, 'id') && is_string($user->id)
                    ? $user->id
                    : '')),
            );
        $app->post('/report', static function (RequestScope $scope) use ($limiter): Text {
            $limiter->hit($scope, 'reports', 1, 3600, 'everyone');

            return new Text('reported');
        });

        return $app;
    }

    private static function from(TestClient $client, string $address, string $path = '/signin'): ResponseInterface
    {
        return $client->request('POST', $path, serverParams: ['REMOTE_ADDR' => $address]);
    }

    public function testLimitsARouteByAddressAndResetsWithTheWindow(): void
    {
        $clock = new FrozenClock('2026-11-14T00:00:10Z');
        $client = new TestClient(self::app(new RateLimiter(new InMemoryRateLimitStore(), $clock))->build());

        $first = self::from($client, '192.0.2.1');
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('"sign-in";r=1;t=50', $first->getHeaderLine('RateLimit'));
        self::assertSame('"sign-in";q=2;w=60', $first->getHeaderLine('RateLimit-Policy'));
        self::assertSame(200, self::from($client, '192.0.2.1')->getStatusCode());

        $limited = self::from($client, '192.0.2.1');
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('50', $limited->getHeaderLine('Retry-After'));
        self::assertSame('rate-limited', ResponseJson::at($limited, '/code'));
        self::assertSame(200, self::from($client, '192.0.2.2')->getStatusCode(), 'Another address has its own count.');

        $clock->advance(50);
        self::assertSame(200, self::from($client, '192.0.2.1')->getStatusCode(), 'A new window starts the count again.');
    }

    public function testLimitsBySubjectAndSkipsAnonymousRequests(): void
    {
        $client = new TestClient(self::app(new RateLimiter(new InMemoryRateLimitStore(), new FrozenClock('2026-11-14T00:00:00Z')))->build());

        self::assertSame(200, $client->post('/posts', '', ['X-User' => 'ada'])->getStatusCode());
        self::assertSame(429, $client->post('/posts', '', ['X-User' => 'ada'])->getStatusCode());
        self::assertSame(200, $client->post('/posts', '', ['X-User' => 'bob'])->getStatusCode());
        self::assertSame(200, $client->post('/posts')->getStatusCode());
        self::assertSame(200, $client->post('/posts')->getStatusCode(), 'Anonymous requests have no subject to count.');
    }

    public function testHitsInsideHandlers(): void
    {
        $client = new TestClient(self::app(new RateLimiter(new InMemoryRateLimitStore(), new FrozenClock('2026-11-14T00:00:00Z')))->build());

        self::assertSame(200, $client->post('/report')->getStatusCode());
        self::assertSame(429, $client->post('/report')->getStatusCode());
    }

    public function testRouteLimitsAreDeclaredFor429(): void
    {
        $routes = self::app(new RateLimiter(new InMemoryRateLimitStore()))->describe()->routes;
        $signIn = array_values(array_filter($routes, static fn ($route): bool => $route->template === '/signin'))[0] ?? self::fail('No route.');

        self::assertSame([429], array_map(static fn ($response): int => $response->status, $signIn->declaredResponses));
    }

    public function testRejectsInvalidLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimiter(new InMemoryRateLimitStore())->limit('sign in!', 0, 60, RateKey::address());
    }
}
