# Rate limits

`shirakaba/rate-limit` counts requests in fixed windows and answers 429 when a limit is exceeded
([security](../design/09-security.md)).

```php
$limiter = new RateLimiter(new DatabaseRateLimitStore($database));

$app->post('/signin', $auth->signIn(...))
    ->middleware($limiter->limit('sign-in', 10, 60, RateKey::address()));
$app->post('/notes', $notes->create(...))
    ->middleware($limiter->limit('posting', 300, 3600, RateKey::subject(static fn (User $user) => $user->id)));
$app->post('/inbox', $inbox->receive(...))
    ->middleware($limiter->limit('inbox', 600, 60, RateKey::custom(
        static fn (ServerRequestInterface $request): ?string => SignatureKeyId::host($request),
    )));
```

- `RateKey::address()` uses the client address from `TrustedProxies` (shirakaba/trusted-proxy),
  so put that middleware first behind a proxy.
- `RateKey::subject()` counts per authenticated principal; requests without one are not counted
  by that limit. Put the authentication middleware before it.
- A key of `null` from `RateKey::custom()` skips the limit for that request.

Limits decided inside a handler, such as per user name after reading the body, call the limiter
directly, before any transaction:

```php
public function signIn(#[JsonBody] Credentials $input, RequestScope $scope): Json
{
    $this->limiter->hit($scope, 'sign-in-account', 5, 300, strtolower($input->username));
    // ...
}
```

`hit()` throws `TooManyRequests`; `attempt()` returns the result instead, with `allowed`,
`remaining` and `retryAfter`. A 429 carries `Retry-After`, the `RateLimit` and `RateLimit-Policy`
headers and the problem code `rate-limited`; route limits add the RateLimit headers to other
responses too, and appear as 429 responses in OpenAPI.

Add `CreateRateLimitsTable` to the migrations and prune old windows from a scheduled job:

```php
$schedule->job('rate-limits.prune', '*/10 * * * *', static fn (): object => new PruneRateLimits());
$jobs->handle(PruneRateLimits::class, static fn (PruneRateLimits $job, RequestScope $scope) => $store->prune(RequestDatabase::writer($database, $scope), $clock->now()));
```

In tests, use `InMemoryRateLimitStore` and a `FrozenClock`, and advance the clock past the
window to reset a limit.
