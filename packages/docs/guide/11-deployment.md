# Deployment

## PHP-FPM

`shirakaba/runtime-fpm`, as in [Getting started](01-getting-started.md). Every request builds
the application; use `build(lazy: true)` so only the requested route is planned.

## FrankenPHP worker mode

```sh
composer require shirakaba/runtime-frankenphp
```

```php
<?php // public/index.php
new FrankenPhpRuntime()->run($builder->build());
```

```sh
frankenphp php-server --worker public/index.php
```

The application is built once and serves many requests (here eager `build()` is right).
After each request, unmoved uploads are removed and garbage is collected; after
`maxRequests` the worker restarts. The runtime conformance suite runs the same checks on
PHP-FPM, FrankenPHP, RoadRunner and Swoole, including that no state leaks between requests.

In worker mode, let the database keep its connection between requests instead of connecting
for each one ([database](../design/08-database.md)); it is rolled back and reset before the next request gets it:

```php
$database = new Database(static fn (): PDO => PDO::connect($dsn, $user, $password), reuse: true);
```

`php-server` sends every request to `public/index.php`. To serve the same entry point with
and without workers, choose the runtime there: under a worker, `$_SERVER['FRANKENPHP_WORKER']`
is set. Caddy compresses responses by default, which buffers Server-Sent Events; start
FrankenPHP with `--no-compress` (or exclude `text/event-stream` from encoding) when you stream.

## RoadRunner

```sh
composer require shirakaba/runtime-roadrunner
```

```php
<?php // worker.php
new RoadRunnerRuntime()->run($builder->build());
```

```yaml
# .rr.yaml
version: "3"
server:
  command: "php worker.php"
http:
  address: 0.0.0.0:8080
  pool:
    num_workers: 4
    max_jobs: 1000
```

```sh
rr serve
```

Each worker builds the application once and serves requests until RoadRunner stops it;
`max_jobs` restarts a worker after that many requests. RoadRunner stores uploads and removes
them after the request. It parses form bodies itself, so the body stream of a form request is
empty; read the fields with `#[FormBody]` or `getParsedBody()`. An exception that escapes the
application is logged by RoadRunner and answered with 500, and the worker keeps serving.

## Swoole

```sh
composer require shirakaba/runtime-swoole
```

```php
<?php // server.php
$server = new Swoole\Http\Server('0.0.0.0', 8080);
$server->set(['worker_num' => 4, 'max_request' => 1000, 'hook_flags' => SWOOLE_HOOK_ALL]);
new SwooleRuntime($server)->run($builder->build());
```

```sh
php server.php
```

The Swoole extension 6.2.5 or later is required. The application is built before the server
starts and copied into each worker; Shirakaba's database pools connect on first use, so
nothing is shared between workers. With `hook_flags`, blocking calls (database, sleeps,
sockets) yield to other requests, so one worker serves many requests at once, and a client
that disconnects from a stream is noticed while the handler waits. Requests get the `https`
scheme when the server's main port uses TLS. An exception that escapes the application goes
to the runtime's `$onError` callback (by default `error_log()`) and is answered with 500.

## Read-only root filesystems

Shirakaba writes nothing to disk at run time, so containers can run with a read-only root
filesystem (for example `ReadonlyRootFilesystem` on AWS ECS) once these have a writable
place:

- `/tmp` (or `upload_tmp_dir` and `sys_get_temp_dir()`): PHP keeps uploaded files there until
  they are moved, and `php://temp` streams spill there beyond 2 MB. Mount a small volume.
  RoadRunner (`http.uploads.dir`) and Swoole (`upload_tmp_dir`) keep their uploads there too.
- FrankenPHP: Caddy keeps data and configuration in `XDG_DATA_HOME` and `XDG_CONFIG_HOME`; mount
  volumes there or point them under `/tmp`. Terminating TLS at the load balancer and turning
  off automatic HTTPS leaves almost nothing to write.
- Sessions: use `DatabaseSessionStore`, which several instances also share, instead of
  `FileSessionStore`.

Logs go to standard error by default (`StreamLogger`), which container platforms collect.

## Behind a proxy

```php
$app->middleware(new TrustedProxies(['10.0.0.0/8'], [ForwardedHeader::For, ForwardedHeader::Proto]));
```

Forwarding headers are ignored unless the direct peer is a trusted proxy ([runtimes and scopes](../design/03-runtimes-and-scopes.md)). Name
only the headers your proxy sets or overwrites: a header it passes through still carries
what the client sent. nginx's usual `proxy_set_header X-Forwarded-For
$proxy_add_x_forwarded_for` and `X-Forwarded-Proto $scheme` match the example; trust
`ForwardedHeader::Host` only if the proxy also sets `X-Forwarded-Host`, or clients can
choose the host in the absolute URLs you build. `ForwardedHeader::Forwarded` (RFC 7239)
cannot be combined with the `X-Forwarded-*` headers.

Put it first, so later middleware sees the client's scheme, host and address
(`ClientAddress::of($request)`).

## Hosts and absolute URLs

Clients choose the `Host` header. Reject hosts the application does not serve, after
`TrustedProxies`:

```php
$app->middleware(
    new TrustedProxies(['10.0.0.0/8'], [ForwardedHeader::For, ForwardedHeader::Proto]),
    new AllowedHosts(['app.example.com', '*.app.example.com']),
);
```

Build links that leave the request, such as password-reset and confirmation URLs in
e-mails or OAuth redirect URIs, from a configured origin instead of the request URI:

```php
$origin = new CanonicalOrigin('https://app.example.com');
$link = $origin->url('/password-reset', ['token' => $token]);
```

## CORS

```php
$app->middleware(new Cors(['https://app.example.com'], allowedHeaders: ['Content-Type', 'Authorization']));
```

## Streaming

`shirakaba/streaming` provides `Streamed` (chunked downloads) and `Sse` (Server-Sent Events).
Resources registered with `RequestScope::defer()` are released when the stream ends, not
when the handler returns ([runtimes and scopes](../design/03-runtimes-and-scopes.md)). Typed events and publish/subscribe are in
[Real-time events](15-real-time-events.md).
