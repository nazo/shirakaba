# Roadmap

Shirakaba is an experiment and has not been released. This page lists what exists and what is
planned; the reasons behind each part are in the [design documents](../packages/docs/design/README.md).

## Available

**Core and runtimes**

- PSR-7 messages and PSR-17 factories (`shirakaba/http-message`)
- Application builder, router, typed argument extraction, Problem Details with stable codes,
  request scope with cleanup, cancellation and deadlines, observer interface and test client
  (`shirakaba/core`)
- Runtimes for PHP-FPM, FrankenPHP worker mode, RoadRunner and Swoole, with a conformance suite
  that checks for state leaking between requests and for client disconnects
- Streaming bodies and Server-Sent Events (`shirakaba/streaming`)
- Lazy builds for PHP-FPM and a benchmark baseline (`docs/benchmarks/`)

**HTTP features**

- JSON bodies and responses mapped to DTOs, maps, optional fields and tagged unions (`shirakaba/json`)
- Constraint attributes (`shirakaba/validation`) and strict date and time types (`shirakaba/time`)
- HTML forms and uploads (`shirakaba/form`), sessions and CSRF protection (`shirakaba/session`,
  with database and Redis stores)
- Bearer authentication and JWT verification (`shirakaba/auth`), CORS, trusted proxies, allowed
  hosts and canonical origins
- Rate limits with 429 and RateLimit headers (`shirakaba/rate-limit`)
- An SSRF-safe PSR-18 HTTP client (`shirakaba/http-client`)
- OpenAPI 3.1 generated from route metadata, including typed event streams and hub channels
  (`shirakaba/openapi`)
- Logging and OpenTelemetry tracing observers

**Data**

- Database access with pooled, leased and reusable connections, reader and writer capabilities,
  several databases, read replicas, statement time limits and row-level security settings
- A typed query builder, an ORM with readonly entities, relations loaded into projections and
  required scopes, migrations with diff and lint, and an opt-in result cache
- The database packages work without the framework; `shirakaba/database-bridge` connects them

**Background work and integration**

- Console commands, background jobs on the database or Redis, scheduled tasks
- Publish/subscribe on PostgreSQL or a Mercure hub, with typed hub events
- Mail over SMTP with templates and sending through the queue
- A small Redis client

**Tooling**

- PHPStan rules: long-running safety, no writes in GET and HEAD handlers, explicit clocks, ORM
  queries, required scopes, queries in loops
- Documentation for coding agents (`shirakaba/docs`, `agents:sync`, `llms.txt`, error and problem
  pages, Skills, introspection commands) and agent evaluation tasks (`tools/evals/`)
- Steins as a pinned, non-blocking CI job
- A Composer repository on GitHub Pages until the packages are published on Packagist

## Planned

- WebSocket and gRPC as runtime packages (Swoole first for WebSocket, RoadRunner for gRPC)
- A template engine for HTML pages, starting from the mail template engine
- Caching packages (PSR-6, PSR-16)
- Attribute-based routing as a separate package
- A compiled route table, if building large route tables under PHP-FPM needs it
- Batches and chains of background jobs, when an application needs them
- OpenTelemetry metrics
- Effect envelopes on public extension points, and Steins as a blocking check, once Steins is
  released and can tell database reads from writes
- A container that generates plain PHP, only if explicit wiring becomes the bottleneck
- Release under the final name: split repositories per package and Packagist
