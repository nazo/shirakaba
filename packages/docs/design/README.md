# Design

Why Shirakaba is built the way it is. Each document covers one area and describes the current
design and the reasons for it, including the alternatives that were not taken. The
[guide](../guide/README.md) explains how to use the packages; these documents explain why they
work as they do.

| Document | Covers |
|---|---|
| [Principles and project structure](01-principles.md) | the minimal core, the dependency policy, configuration as code, service wiring, the PHP version, the monorepo, distribution |
| [HTTP messages](02-http-messages.md) | the PSR-7 and PSR-17 implementation: URIs, headers, streams, uploaded files |
| [Runtimes, scopes and long-running processes](03-runtimes-and-scopes.md) | worker-runtime safety, application and request scope, cleanup, cancellation, the HTTP boundary, streaming bodies, WebSocket and gRPC |
| [Routing and handlers](04-routing-and-handlers.md) | route declaration and matching, typed argument extraction, return values, route metadata and constraints |
| [Errors and observability](05-errors-and-observability.md) | error classes, Problem Details and problem codes, observers, logging, tracing |
| [JSON, responses and OpenAPI](06-json-and-openapi.md) | JSON bodies and DTO mapping, responses, declared responses, tagged unions, OpenAPI generation |
| [Date and time](07-date-and-time.md) | instants, clocks, input and output formats, calendar dates |
| [Database](08-database.md) | the database packages, reads and writes, connections in worker runtimes, replicas, the ORM, caching, migrations |
| [Authentication, sessions, outgoing requests and rate limits](09-security.md) | bearer tokens and JWT, sessions and CSRF, the SSRF-safe HTTP client, rate limits |
| [Commands, background jobs and scheduled tasks](10-background-work.md) | the console, the queue, the schedule, the Redis client |
| [Real-time events](11-real-time-events.md) | typed Server-Sent Events, publish/subscribe, Mercure, typed hub channels |
| [Mail and templates](12-mail-and-templates.md) | the template engine, the mailer, sending through the queue |
| [Static analysis and coding agents](13-static-analysis-and-agents.md) | PHPStan rules, effects and Steins, documentation for coding agents |

A change to the design updates the relevant document in the same commit as the code.
