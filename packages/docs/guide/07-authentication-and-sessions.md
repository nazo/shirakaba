# Authentication, sessions and CSRF

## Bearer tokens and JWT

```sh
composer require shirakaba/auth
```

```php
$verifier = new JwtVerifier(
    [JwtKey::rsa($publicKeyPem, 'key-2026')],
    static fn (array $claims, RequestScope $scope): ?User => new Users(RequestDatabase::reader($scope->get(Database::class), $scope))->find($claims['sub'] ?? ''),
    issuer: 'https://auth.example.com',
    audience: 'api',
);
$app->middleware(new BearerAuthentication($verifier));

public function me(#[Authenticated] User $user): Json      // 401 without a valid token
public function feed(#[Authenticated] ?User $user): Json   // optional authentication
```

`JwtVerifier` supports HS256, RS256 and ES256. The algorithm comes from the key, never
from the token; `exp` is required; issuer and audience are checked when configured
([security](../design/09-security.md)). For anything else, implement `TokenVerifier`. It is built once with the
application and receives each request's scope, so a verifier for opaque tokens stored in
the database reads them through the request's connection; add it after
`DatabaseMiddleware`:

```php
final readonly class StoredTokens implements TokenVerifier
{
    public function verify(string $token, RequestScope $scope): ?object
    {
        $row = Entities::query(AccessToken::class)
            ->where('tokenHash', '=', hash('sha256', $token))
            ->first(RequestDatabase::reader($scope->get(Database::class), $scope));

        return $row === null ? null : new Viewer($row->userId);
    }
}

$app->middleware(new DatabaseMiddleware($database), new BearerAuthentication(new StoredTokens()));
```

Middleware that authenticates in its own way can answer 401 with the same header through
`BearerAuthentication::challenge('api', 'invalid_token')`.

## Sessions and CSRF

```sh
composer require shirakaba/session
```

```php
$app->middleware(
    new SessionMiddleware(new FileSessionStore('/var/lib/app/sessions')),
    new CsrfProtection(['https://app.example.com']),
);

public function login(RequestScope $scope, #[FormBody] Login $form): Response
{
    $session = Session::of($scope);
    $session->regenerate();               // prevents session fixation
    $session->set('user', $user->id);
    $session->flash('notice', 'Welcome back');
    ...
}
```

`regenerate()` at login and `invalidate()` at logout also replace the CSRF token, so a
token seen before the change no longer works; forms rendered before it must be reloaded.

Pick the store by how the application runs:

| Store | Use it for |
| --- | --- |
| `DatabaseSessionStore` (`shirakaba/session-database`) | several instances behind a load balancer, containers with a read-only root filesystem |
| `RedisSessionStore` (`shirakaba/session-redis`) | the same, when Redis (or Valkey, ElastiCache) is at hand; Redis expires sessions itself |
| `FileSessionStore` | one server with a writable, persistent directory |
| `InMemorySessionStore` | tests, or a single worker process |

```php
$app->middleware(new SessionMiddleware(new DatabaseSessionStore($database)), new CsrfProtection([...]));

// migrations: '2026_11_18_create_sessions' => new CreateSessionsTable(),
// a scheduled job: $store->prune($writer);
```

`RedisSessionStore` takes a `RedisClient` (`shirakaba/redis`, `RedisClient::fromUrl('rediss://user:password@host:6379/0')`)
and answers 503 when Redis cannot be reached rather than starting an empty session.

`DatabaseSessionStore` keys rows by the SHA-256 hash of the session ID, so reading the table
does not hand out live sessions, and saves outside transactions on the request's connection,
so a request that rolls back keeps its session. Expired rows are never loaded; `prune()`
deletes them.

`FileSessionStore` writes files readable only by their owner. Expired sessions that are
never read again stay on disk until `collectGarbage()` runs, so call it from a scheduled
job.

A store of your own implements `SessionStore`; each call receives the request scope.
`SessionStoreChecks::run($store, $clock, $scope)` returns what it gets wrong (expiry,
replacing, deleting, keeping sessions apart), for a test.

Sessions live in the request scope and never use PHP's `session_*` functions, so they are
safe under worker runtimes. Cookies default to `Secure`, `HttpOnly` and `SameSite=Lax`.
`CsrfProtection` rejects cross-site state changes using `Sec-Fetch-Site` and `Origin`, and
falls back to a token from `CsrfProtection::token($session)` in a `_csrf` field or an
`X-CSRF-Token` header.
