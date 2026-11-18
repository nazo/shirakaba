# Authentication, sessions, outgoing requests and rate limits

Security-relevant code that other frameworks take from libraries is written here, small and
tested for the attacks that matter, because the dependency policy limits third-party runtime
libraries ([principles](01-principles.md)). This document covers the packages whose main job is
security. Header injection, path decoding and Host handling are covered in
[HTTP messages](02-http-messages.md), [routing](04-routing-and-handlers.md) and
[runtimes](03-runtimes-and-scopes.md).

## Bearer tokens and JWT

`shirakaba/auth` provides the `BearerAuthentication` middleware, the `TokenVerifier` interface and
the `#[Authenticated]` extractor.

- The middleware verifies an `Authorization: Bearer` credential if present and registers the
  principal in the request scope. An invalid token is a 401 with `WWW-Authenticate: Bearer` and
  `error="invalid_token"` (RFC 6750). A missing token is not an error by itself: handlers that need
  a principal declare `#[Authenticated] User $user`, which answers 401 when there is none.
- The extractor describes itself with the source `bearer`, so OpenAPI documents the security
  requirement and the 401.
- `TokenVerifier::verify(string $token, RequestScope $scope)` receives the request's scope, so a
  verifier that looks tokens up (opaque tokens in the database) uses the request's connection.
  `BearerAuthentication::challenge()` is public for middleware that authenticates another way and
  answers 401 the same way.

`JwtVerifier` is a minimal implementation built only on `hash_hmac`, `hash_equals` and ext-openssl:

- algorithms HS256, RS256 and ES256 only. The algorithm comes from the configured key, never from
  the token (the header must match it), which rules out algorithm confusion and `none`;
- keys are selected by `kid` when keys have IDs; a `crit` header is rejected, since no extensions are
  supported;
- `exp` is required by default, `nbf` and `iat` are checked, a small leeway absorbs clock skew, and
  the clock is injectable; issuer and audience are checked when configured;
- base64url decoding is strict, and the header and claims must be JSON objects.

The tests cover signature tampering, algorithm confusion, `none`, expired and premature tokens, and
wrong issuers and audiences. Token issuing is out of scope; applications that need JWE, other
algorithms or JWKS fetching implement `TokenVerifier` with a library of their choice.

## Sessions and CSRF

`shirakaba/session` keeps sessions in the request scope and never uses PHP's `session_*` functions,
so they are safe under worker runtimes.

- Cookies default to `Secure`, `HttpOnly` and `SameSite=Lax`. `regenerate()` at login and
  `invalidate()` at logout replace the session ID and the CSRF token, which prevents session fixation.
- `CsrfProtection` rejects cross-site state changes using `Sec-Fetch-Site` and `Origin` against the
  configured origins.
- Stores: `DatabaseSessionStore` (`shirakaba/session-database`) keys rows by the SHA-256 hash of the
  session ID, so reading the table does not hand out live sessions, and saves outside transactions so
  a request that rolls back keeps its session. `RedisSessionStore` (`shirakaba/session-redis`) answers
  503 when Redis cannot be reached rather than starting an empty session. `FileSessionStore` writes
  files readable only by their owner. Session data is JSON, never `serialize()`.

## Calling other servers

Applications call other servers: object storage, webhooks, and federated protocols whose URLs come
from other servers' documents. A URL chosen by someone else can point at loopback, the private network
or a cloud metadata endpoint (SSRF), answer slowly, send an endless body or redirect somewhere worse.
The checks must use the address actually connected to: checking a name and letting the transport
resolve it again allows DNS rebinding.

`shirakaba/http-client` provides `HttpClient`, a PSR-18 client on ext-curl, so signing libraries and
SDKs remain usable.

- The client resolves every host itself and checks every address; one refused address refuses the
  host. curl is then pinned to a checked address (`CURLOPT_RESOLVE`). By default only globally
  routable addresses are allowed (RFC 6890, with IPv4-mapped, NAT64 and compatible IPv6 judged by the
  embedded IPv4); `allowAddress` replaces the policy. Only http and https are allowed, and proxies
  from the environment are ignored.
- Redirects are followed by the client, not curl, up to `maxRedirects`, each hop resolved and checked
  again. 303, and 301/302 after POST, become GET without a body. `Authorization`, `Cookie` and
  `Proxy-Authorization` are dropped when the origin changes.
- `prepare` runs on every request sent, each redirect included, so signatures (HTTP Message
  Signatures, RFC 9421) cover the request actually sent.
- One `timeout` covers the whole exchange, redirects included; `connectTimeout` limits each
  connection; `maxResponseBytes` aborts the transfer as soon as more arrives. Responses are buffered in
  memory up to that limit.
- `$client->in($scope)` binds the client to a request: it stops when the request is cancelled or its
  deadline passes, and reports an event for every request sent, without path, query or headers.
- Failures are PSR-18 exceptions: `RequestRefused` (nothing was sent to the refused target) and
  `NetworkError` (with `timedOut` and `tooLarge`).

Tests that call a local server allow its address explicitly with `allowAddress`.

## Rate limits

Public endpoints need limits: sign-in attempts per address and per account, sign-ups per address,
posts per user, deliveries per remote server. A limit must hold across processes and servers, be
testable with a frozen clock, answer with a standard 429 and appear in the API description.

- `shirakaba/rate-limit`'s `RateLimiter` applies fixed windows: at most `limit` hits per `seconds` for
  each name and key. Fixed windows need one counter per window and one atomic upsert per hit; the
  price is that a client can make up to twice the limit across a window boundary, which is acceptable
  for abuse protection. Smoother algorithms can be added behind the same API.
- Limits are declared per route as middleware, `$limiter->limit($name, $limit, $seconds, $key)`,
  keyed by `RateKey::address()` (the client address from `shirakaba/trusted-proxy`, else
  `REMOTE_ADDR`), `RateKey::subject($id)` (the authenticated principal; anonymous requests are not
  counted) or `RateKey::custom()`. Handlers decide others with `hit()` or `attempt()`.
- Exceeding a limit throws `TooManyRequests`: a 429 with `Retry-After`, the IETF `RateLimit` and
  `RateLimit-Policy` headers and the problem code `rate-limited`. Route limits add the RateLimit
  headers to successful responses too, and declare the 429 so OpenAPI lists it.
- `DatabaseRateLimitStore` counts in one table shared by every process, with one upsert per hit on the
  request's connection. It refuses to count inside a transaction, whose rollback would undo the hit (a
  failed sign-in must still count). `prune()` deletes expired windows from a scheduled job. Keys are
  stored as SHA-256 digests, so addresses and user names are not kept in the table.
  `InMemoryRateLimitStore` serves tests.
