# Runtimes, scopes and long-running processes

Shirakaba runs under PHP-FPM, where every request starts a fresh process state, and under
worker runtimes (FrankenPHP worker mode, RoadRunner and Swoole), which keep the application
in memory across requests. State that leaks from one request into another causes
data exposure bugs that are hard to find, and retrofitting safety into a framework built for
the shared-nothing model is expensive. Shirakaba is designed for worker runtimes from the start.

## Long-running process safety

- Every package must be correct when one application instance handles many requests, one after
  another or concurrently (Swoole coroutines, fibers).
- Two scopes are explicit in the API. **Application scope** is built once and immutable after
  boot. **Request scope** is created per request and discarded after it. Request data travels
  with the request, never in static or global state, and never in properties of
  application-scoped objects such as middleware, handlers or extractors.
- Mutable static properties and global variables are forbidden in packages; custom PHPStan
  rules enforce this as far as they can ([static analysis](13-static-analysis-and-agents.md)).
- Shared resources are not "reset after each request", because under concurrency another
  request may be using them. Pools stay in application scope and hand out leases; the request
  scope owns a lease and releases it.
- A runtime conformance suite (`tests/conformance`) runs the same application through every
  runtime package and checks that nothing leaks between requests.

APIs therefore never rely on "current request" singletons: they receive the request or a
request-scoped value explicitly. Time comes from a PSR-20 clock ([date and time](07-date-and-time.md)).

## Application scope and request scope

- **Application scope** holds the route table, handler plans, factories, immutable
  configuration and concurrency-safe pools. It does not change after `build()`.
- **Request scope** (`RequestScope`) is created by the application for each request and
  attached to the request as an attribute. It holds values registered for the request by type
  (`set()`, `get()`, `find()`), cleanup callbacks (`defer()`), a `Cancellation` and an optional
  `Deadline`. It is not a container: it never constructs objects.
- Middleware registers values (the authenticated principal, for example); handlers and
  extractors read them. There is no global "current request" accessor.

### Cleanup

Cleanup callbacks run in reverse order of registration when the scope closes. Every callback
runs even if an earlier one fails; failures are reported to the observer, and a cleanup failure
never replaces the response or the original error. Destructors are never the only release
mechanism.

The application closes the request scope when `handle()` returns, unless the response body
takes over closing it (streaming, below). Resources registered with the scope must therefore not
be needed by an ordinary response body.

### Cancellation and deadlines

Both are cooperative. Runtimes pass them in through `Application::handleWith()`; `handle()` uses
no deadline and a cancellation that is never requested. Code checks them between steps; nothing
interrupts a blocking call, so runtimes and drivers still need their own timeouts.

## Runtime packages

Each runtime is a separate package (`shirakaba/runtime-fpm`, `shirakaba/runtime-frankenphp`,
`shirakaba/runtime-roadrunner` and `shirakaba/runtime-swoole`). A runtime turns its native request
into a PSR-7 server request, calls the kernel, emits the PSR-7 response and closes the body.
Runtime packages are thin adapters, because the kernel behaves the same everywhere. "Response
sent" and send failures are runtime events, distinct from "response ready" (the kernel returned).

### RoadRunner and Swoole

Neither server fills in superglobals; each hands the worker its own request object.

- **One conversion.** Both runtimes turn that request into server variables (`REQUEST_URI`,
  `HTTP_*`) and build the PSR-7 request with `ServerRequestBuilder::build()`, so the rules of
  the HTTP boundary below are written once. Header names containing `_` are dropped, as nginx
  and Apache do, because `X_Forwarded_For` and `X-Forwarded-For` would become the same variable
  and a client could pose as a proxy. Repeated headers are joined with `, ` (`Cookie` with `; `).
- **Bodies.** RoadRunner parses form bodies itself and passes the fields instead of the raw
  body, so under RoadRunner a form request's body stream is empty, like `php://input` for
  `multipart/form-data` under PHP-FPM. Both servers store uploads and remove the files after
  the request; `UploadedFile::moveTo()` renames them.
- **Responses.** A body whose end is reached with its first chunk (64 KiB) is sent whole; any
  other body is sent chunk by chunk as it is produced, as a RoadRunner stream or with Swoole's
  `write()`. The servers frame the body, so Swoole drops the application's `Content-Length`
  and `Transfer-Encoding`.
- **Disconnects.** RoadRunner tells the worker that the client went away and throws
  `StreamStoppedException` into the chunk generator; Swoole's `write()` returns false. Either
  way the runtime requests the `Cancellation` and closes the body. Swoole notices a closed
  connection only while its event loop runs, so a handler that blocks between chunks needs
  coroutine hooks (`hook_flags`) for the disconnect to be seen.
- **Failures.** An exception that escapes the application must not end the worker: RoadRunner
  receives it as a worker error, logs it and answers 500; Swoole passes it to an error
  callback (by default `error_log()`) and answers 500, because ending a Swoole worker would
  also end the other requests its coroutines are serving.
- **Lifetimes.** RoadRunner restarts workers after `http.pool.max_jobs` requests and Swoole
  after `max_request`, so the runtimes have no request limit of their own. A Swoole
  application is built in the master process and copied into every worker; building must
  not open connections, which the database pools already open on first use.
- **Versions.** The Swoole runtime refuses Swoole releases before 6.2.5, in which a crafted
  multipart request could name an upload path of its choosing (which Swoole later deleted).
  The extension is a suggestion rather than a requirement, so the monorepo installs without
  it; PHPStan reads the `swoole/ide-helper` stubs.

### The HTTP boundary

Some inputs a runtime receives are attacker-controlled and ambiguous.

- **Host.** The request URI's host and port come from the `Host` header, falling back to the
  server's configured name and port. A `Host` that is not a valid `host[:port]` makes the runtime
  answer 400 without calling the application.
- **Forwarding headers.** `Forwarded` and `X-Forwarded-*` are passed through as ordinary headers
  but never change the URI, scheme or client address. Which proxies to trust is a deployment
  decision, made with `shirakaba/trusted-proxy`, configured with the proxies' addresses and the
  headers they set. There is no default header set, because proxies commonly pass headers they
  do not set straight through; `Forwarded` and `X-Forwarded-*` cannot be trusted together. The
  same package has `AllowedHosts` (400 for hosts the application does not serve) and
  `CanonicalOrigin` (absolute URLs from a configured origin rather than the request), which
  limit Host header attacks such as poisoned password-reset links.
- **Request target.** An absolute-form target (`GET http://host/path`) contributes only its path
  and query; the host still comes from `Host`.
- **Body framing.** Runtimes receive bodies already framed by their server, so they do not parse
  `Content-Length` or `Transfer-Encoding`. A runtime that ever parses raw HTTP must reject
  requests carrying both.
- **Superglobals.** `ServerRequestBuilder::fromSuperglobals()` in `shirakaba/sapi` is the only
  code that reads superglobals; the conversion itself takes arrays, which the RoadRunner and
  Swoole runtimes fill from their servers' requests.

## Streaming responses

A body that keeps producing data after `handle()` returns (a large export from a database cursor,
Server-Sent Events) would find its resources already released. Plain PSR-15 callers know nothing
about scopes, so the solution must not break them.

- A response body may implement `ScopeOwningBody`. The application then does not close the
  request scope itself; it hands the body a callback that closes it. The body calls it exactly
  once: when it reaches the end, is closed, is detached or, as a last resort, is destroyed.
- Bodies that do not implement the interface behave as above. A body replaced later (by the HEAD
  fallback, which empties the body) releases the scope when destroyed.
- After sending a response, successfully or not, a runtime calls `close()` on the body, which
  releases the scope promptly instead of at garbage collection.
- Streaming bodies (`shirakaba/streaming`) are read-only, non-seekable and of unknown size.
  `read()` blocks until data is available and returns `""` only at the end, so emitters treat an
  empty read as the end without busy-waiting. Emitters write and flush each chunk as it is
  produced; under PHP-FPM the emitter flushes PHP's output buffers after each chunk.
- **Disconnects.** The SAPI emitter checks `connection_aborted()` after each chunk. When the
  client is gone it stops writing, requests the request's `Cancellation` and closes the body, so
  a generator stops and the scope's cleanup runs. PHP notices a disconnect only when a write
  fails, so detection lags a chunk or two. Runtimes call `ignore_user_abort(true)`; otherwise PHP
  would end the script at the failed write and skip the cleanup.

```php
$app->get('/events', static function (RequestScope $scope) use ($feed): Sse {
    $subscription = $feed->subscribe();
    $scope->defer($subscription->close(...)); // runs after the stream ends, not when the handler returns

    return new Sse((static function () use ($subscription, $scope): Generator {
        foreach ($subscription as $message) {
            $scope->cancellation()->throwIfRequested();
            yield new SseEvent($message->json(), event: 'message', id: $message->id);
        }
    })());
});
```

A PSR-15 caller that never reads or closes a streaming body keeps the scope open until the body
is garbage-collected.

## WebSocket and gRPC

WebSocket connections and gRPC calls do not fit PSR-15's one request, one response model, and both
depend on what the server provides. They are planned as runtime-specific packages, never part of
the core:

- they share the HTTP side's machinery: a `RequestScope` per connection or call, observers, the
  long-running safety rules and explicit wiring;
- a WebSocket upgrade request is routed and authenticated by the ordinary application, which
  returns a value telling the runtime to accept the connection and which handler (`open`,
  `message`, `close`) to attach. Per-connection state lives in the connection's scope, never in
  the handler object. Broadcasting goes through an interface the runtime implements;
- gRPC starts with unary calls mapped to plain PHP service interfaces generated from `.proto`
  files; Protobuf dependencies stay in the integration package;
- Swoole is the first candidate for WebSocket and RoadRunner for gRPC.

Server-Sent Events cover one-way pushes without a special runtime and are the recommended first
choice ([real-time events](11-real-time-events.md)).
