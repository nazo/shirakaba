# Errors, logging and tracing

## Errors

Throw `HttpFailure` for errors meant for clients:

```php
throw HttpFailure::notFound(sprintf('Task %d does not exist.', $id));
throw HttpFailure::unauthorized('Bearer realm="api"');
throw new HttpFailure(409, 'Already archived.', type: 'https://example.com/problems/archived');
```

They become RFC 9457 Problem Details (`application/problem+json`). Any other exception is
a bare 500 whose body never contains the message ([errors and observability](../design/05-errors-and-observability.md)). Replace the format, for
example with HTML pages, through `$app->renderErrorsWith($renderer)`; required headers such
as `Allow` and `WWW-Authenticate` are kept whatever the renderer does.

Libraries that do not know HTTP throw their own exceptions. A `TranslatesFailures` turns
them into failures: register one with `$app->translateFailures($translator)`, or let global
middleware implement it, as `DatabaseMiddleware` does to answer the ORM's `EntityNotFound`
with a 404 and `StaleEntity` with a 409 ([database](../design/08-database.md)). What a translator returns is shown to
clients, so pass on only messages written for them.

Problems Shirakaba produces itself carry a stable `code` (`invalid-body`, `missing-parameter`,
...), and each entry of `errors` has one too (`required`, `unknown-field`,
`invalid-date-time`, ...), so clients and tests can branch without parsing the English
`detail`. The codes are constants of `ProblemCode`, each explained in
`vendor/shirakaba/docs/problems/`. Give your own failures a code the same way:
`new HttpFailure(409, 'Already archived.', extensions: ['code' => 'already-archived'])`.

## Logging

Because 500 responses are bare, production needs an observer that records the cause:

```sh
composer require shirakaba/log
```

```php
$app->observe(new LoggingObserver(new StreamLogger(), accessLog: true));
```

Unexpected errors are logged at `error` with the exception, client errors at `debug`,
cleanup failures at `warning`. Access lines use the route template, never the raw path.
`StreamLogger` writes JSON lines to standard error; any PSR-3 logger works.

## Tracing

```sh
composer require shirakaba/opentelemetry open-telemetry/sdk
```

```php
$app->observe(new TracingObserver($tracerProvider->getTracer('my-api')));
```

One server span per request, named after the route (`GET /tasks/{id}`), continuing the
caller's `traceparent`; only 5xx responses mark it as an error ([errors and observability](../design/05-errors-and-observability.md)).

Observers combine: `$app->observe(new LoggingObserver($logger), new TracingObserver($tracer))`.
A failing observer never affects the response or the other observers.

## Database statements

With `shirakaba/database`, every statement a request runs is reported to observers: the
access log line ends with `db.statement=3` and carries the count and total time, and the
request span gets one `db.statement` event per statement (with `db.query.text`, never the
parameter values) and `shirakaba.db.statement.count` / `.duration` totals. Packages can
report their own events with `RequestScope::record()`.
