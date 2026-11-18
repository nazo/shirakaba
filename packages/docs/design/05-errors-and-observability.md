# Errors and observability

Errors must produce consistent responses without leaking internals, and what happens inside a
request must be visible to logging and tracing without the core depending on either.

## Classes of error

1. `HttpFailure`: thrown deliberately by application code or packages, carrying a status, an
   optional public detail, an optional `type` URI, a problem code and headers.
2. `ExtractionFailure` (a 400 `HttpFailure`): a parameter could not be extracted.
3. Any other `Throwable`: unexpected. The response is a 500 with no message, class name or trace
   in the body. Observers receive the exception.

Packages can keep their exceptions free of HTTP: before an exception becomes a bare 500, the
router asks the translators added with `AppBuilder::translateFailures()`, and global middleware
that implements `TranslatesFailures`, whether it maps to an `HttpFailure`. The database bridge
answers the ORM's "entity not found" and "stale entity" this way with 404 and 409
([database](08-database.md)).

## Problem Details

Errors are RFC 9457 Problem Details, `application/problem+json`.

- `status` always equals the HTTP status. Without a specific `type`, the type is `about:blank`
  and the title is the status's reason phrase.
- `detail` contains only text meant for clients; extraction failures name the parameter and the
  reason but never echo the received value.
- Required headers are set: `Allow` on 405, `WWW-Authenticate` when the failure provides it.
- Every problem Shirakaba sends has a stable `code` member, and each entry of `errors` (one per
  field, with a JSON Pointer) has its own `code`. Clients and tests branch on codes rather than on
  the English detail. The codes are listed in `ProblemCode`, and each has a page under
  `problems/` in `shirakaba/docs`.

| Status | Cause |
|---|---|
| 400 | NUL in the path, extraction failure, malformed or mismatching body |
| 404 | no route matches the path |
| 405 | the path matches, the method does not (with `Allow`) |
| 413, 415, 422 | body limits, media type and constraints (JSON, forms) |
| 429 | a rate limit was exceeded ([security](09-security.md)) |
| 500 | unexpected `Throwable`, invalid handler return value |
| any | an `HttpFailure` thrown by the application |

**Where errors are caught.** The router renders failures from matched routes (route and group
middleware, extraction, handlers), so global middleware sees an error response and can decorate
it. An outer boundary renders failures thrown by global middleware itself. Each failure is
reported to observers once. Once a runtime has started sending a response, an error cannot become
a 500; runtimes report it as a send failure.

**Rendering is replaceable.** Responses are produced by an `ErrorRenderer`; `ProblemDetails` is the
default and `AppBuilder::renderErrorsWith()` installs another (HTML pages, content negotiation).
The guarantees do not depend on the renderer: renderers receive a `Failure` value, never a
throwable, so they cannot leak one; the failure's headers are applied again to whatever the
renderer returns; and the same renderer serves router errors, route failures and failures in
global middleware.

Errors raised while *building* an application (`InvalidApplication`, `MappingError`,
`ScopeMissing`) also carry stable codes and end with the path of a page under `messages/` that
explains the fixes ([static analysis and agents](13-static-analysis-and-agents.md)).

## Observers

The core defines a small, dependency-free observer interface. Observers are passed to the
application explicitly; there is no global registry.

- `Observer::begin()` returns a per-request `Observation`, which receives route matched, handler
  finished, failure and response ready. Several observers can be added; each receives every
  event.
- Observer exceptions are caught and never change the response or the other observers.
- "Response ready" (the kernel returned) and "response sent" (the runtime finished writing) are
  different events; the second belongs to runtime packages.
- `RequestSummary` carries the W3C trace propagation headers (`traceparent`, `tracestate`,
  `baggage`) and no other request header.
- Standard events never carry raw paths, query strings, cookies or `Authorization`; route
  templates identify requests, which also keeps metric cardinality bounded.
- Packages report what happens inside a request with `RequestScope::record(Event)`; observers
  receive it as `Observation::recorded()`. An `Event` has a dotted name, an optional duration and
  attributes that are safe to log (never parameter values), so observers can summarise events they
  do not know. Database statements, connection leases, cache lookups, outgoing HTTP requests and
  sent mail are reported this way.

Applications that do not observe pay only for a no-op observer, and other backends can attach to
the same interface. The interface is public API and must stay small and stable.

## Logging

`shirakaba/log` provides `LoggingObserver` for any PSR-3 logger: unexpected exceptions and 5xx
failures at `error` with the exception in the context, 4xx failures at `debug`, cleanup failures at
`warning`, and an optional `info` access line per request using the route template, never the raw
path. `StreamLogger` (JSON lines to standard error) makes logging work without another dependency.
`$app->observe(new LoggingObserver(new StreamLogger()))` is the minimum for production, since
unexpected errors otherwise leave no trace.

## Tracing

`shirakaba/opentelemetry` provides `TracingObserver`: one server span per request, continuing the
caller's trace from the propagation headers, named after the route template, with
`http.request.method`, `http.route` and `http.response.status_code`. Exceptions are recorded as
events; only 5xx responses mark the span as an error, as the HTTP semantic conventions require for
servers. The span's context is active while the request runs, so instrumentation in handlers
creates children. The package depends on `open-telemetry/api` only; the SDK is the application's
choice. Under coroutine runtimes, activating the span relies on OpenTelemetry's context storage
being coroutine-aware, which is the application's configuration.

Background jobs report to the same observers, and their traces continue from the request that
enqueued them ([background work](10-background-work.md)).
