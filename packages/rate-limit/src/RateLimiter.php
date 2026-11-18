<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Applies rate limits in fixed windows (design/09-security.md): at most $limit hits per $seconds for each
 * name and key.
 *
 *     $limiter = new RateLimiter(new DatabaseRateLimitStore($database));
 *     $app->post('/signin', $auth->signIn(...))->middleware($limiter->limit('sign-in', 10, 60, RateKey::address()));
 *     $limiter->hit($scope, 'sign-in-user', 5, 300, $username);   // inside a handler, before any transaction
 *
 * Keys are stored as digests, never as given.
 */
final readonly class RateLimiter
{
    public function __construct(
        private RateLimitStore $store,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * Route middleware that answers 429 when the limit is exceeded, adds the RateLimit headers
     * to responses, and declares the 429 for OpenAPI.
     */
    public function limit(string $name, int $limit, int $seconds, RateKey $key, bool $headers = true): RateLimitMiddleware
    {
        self::check($name, $limit, $seconds);

        return new RateLimitMiddleware($this, $name, $limit, $seconds, $key, $headers);
    }

    /**
     * Counts a hit and reports whether it is within the limit.
     *
     * @throws InvalidArgumentException for an empty name or a non-positive limit or window
     */
    public function attempt(RequestScope $scope, string $name, int $limit, int $seconds, string $key): RateLimitResult
    {
        self::check($name, $limit, $seconds);
        $now = $this->clock->now()->getTimestamp();
        $start = intdiv($now, $seconds) * $seconds;
        $utc = new DateTimeZone('UTC');
        $window = new DateTimeImmutable('@' . $start)->setTimezone($utc);
        $reset = new DateTimeImmutable('@' . ($start + $seconds))->setTimezone($utc);
        $hits = $this->store->hit($scope, $name . ':' . hash('sha256', $key), $window, $reset);

        return new RateLimitResult($name, $hits <= $limit, $limit, max(0, $limit - $hits), $seconds, $reset, max(1, $start + $seconds - $now));
    }

    /**
     * Counts a hit and throws a 429 when it is over the limit.
     *
     * @throws TooManyRequests when the limit is exceeded
     */
    public function hit(RequestScope $scope, string $name, int $limit, int $seconds, string $key): RateLimitResult
    {
        $result = $this->attempt($scope, $name, $limit, $seconds, $key);

        return $result->allowed ? $result : throw new TooManyRequests($result);
    }

    private static function check(string $name, int $limit, int $seconds): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name) !== 1 || $limit < 1 || $seconds < 1) {
            throw new InvalidArgumentException('A rate limit needs a name of letters, digits, ".", "_" or "-", and a positive limit and window.');
        }
    }
}
