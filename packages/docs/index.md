# Shirakaba documentation for coding agents

Shirakaba is a PHP 8.5 web framework that is **not in your training data**. Do not guess its
API from Laravel, Symfony, Slim or other frameworks: names and conventions differ, and
several common patterns are deliberately absent. Read the relevant page below before writing
code, and check the class you use in [api.md](api.md).

This documentation matches the installed version of Shirakaba.

## Where to look

| Task | Read |
|---|---|
| Start an application, entry points, runtimes | [guide/01-getting-started.md](guide/01-getting-started.md), [guide/11-deployment.md](guide/11-deployment.md) |
| Routes, handlers, middleware | [guide/02-routing-and-handlers.md](guide/02-routing-and-handlers.md) |
| Path, query, header and cookie parameters; dates | [guide/03-request-data.md](guide/03-request-data.md) |
| JSON request bodies and responses, DTOs | [guide/04-json-apis.md](guide/04-json-apis.md) |
| HTML forms and uploads | [guide/05-forms-and-uploads.md](guide/05-forms-and-uploads.md) |
| Database, query builder, ORM, migrations, caching | [guide/06-database.md](guide/06-database.md) |
| Authentication, sessions, CSRF | [guide/07-authentication-and-sessions.md](guide/07-authentication-and-sessions.md) |
| Errors, logging, tracing | [guide/08-errors-and-observability.md](guide/08-errors-and-observability.md) |
| Tests | [guide/09-testing.md](guide/09-testing.md) |
| OpenAPI | [guide/10-openapi.md](guide/10-openapi.md) |
| PHPStan rules | [guide/12-static-analysis.md](guide/12-static-analysis.md) |
| Console commands | [guide/13-command-line.md](guide/13-command-line.md) |
| Background jobs | [guide/14-background-jobs.md](guide/14-background-jobs.md) |
| Server-Sent Events, publish/subscribe | [guide/15-real-time-events.md](guide/15-real-time-events.md) |
| Calling other servers (HTTP client, SSRF) | [guide/16-calling-other-servers.md](guide/16-calling-other-servers.md) |
| Rate limits (429) | [guide/17-rate-limits.md](guide/17-rate-limits.md) |
| Mail, mail templates | [guide/18-sending-mail.md](guide/18-sending-mail.md) |
| An error that ends with "See vendor/shirakaba/docs/messages/..." | [messages/README.md](messages/README.md) |
| The `code` of a Problem Details response | [problems/README.md](problems/README.md) |
| Step-by-step workflows (endpoint, entity, query audit, job) | [skills/README.md](skills/README.md) |
| Why something is designed the way it is | [design/README.md](design/README.md) |
| Every public class with a one-line summary | [api.md](api.md) |

## Rules agents most often get wrong

- **Configuration is PHP code.** There are no config files, no service container and no
  autowiring. Dependencies are passed to constructors and closures in a builder function
  ([principles](design/01-principles.md)).
- **Handlers declare what they read.** Parameters carry `#[Path]`, `#[Query]`, `#[Header]`,
  `#[Cookie]`, `#[JsonBody]`, `#[FormBody]` or `#[Db]`; values are parsed strictly and bad
  input is a 400 Problem Details response, never a PHP warning.
- **No global state.** Nothing reads superglobals or static properties; per-request values
  live in the `RequestScope`. Code must be safe in worker runtimes that serve many requests
  per process ([runtimes and scopes](design/03-runtimes-and-scopes.md)).
- **GET and HEAD handlers take a `Reader`, never a `Writer`.** PHPStan reports violations
  ([static analysis and agents](design/13-static-analysis-and-agents.md) and [database](design/08-database.md)).
- **Entities are `final readonly` classes.** There is no unit of work, no `save()`, no
  lazy loading: write with `Entities::insert()`, `update()`, `patch()`, `delete()`, and load
  relations with `with(...)->into(Projection::class)` ([database](design/08-database.md)). Change an entity with
  `Entities::change($entity, [...])`.
- **Instants are `DateTimeImmutable` in UTC; dates are `Shirakaba\Time\LocalDate`.** Input must
  be RFC 3339 with an offset. Read the current time from a PSR-20 `ClockInterface`, never
  `time()` or `new DateTimeImmutable()` ([date and time](design/07-date-and-time.md)).
- **Entities with `#[RequiredScope]` need `scoped(...)` on reads and `scopes:` on writes**;
  forgetting it throws `ScopeMissing`.
- **Errors are Problem Details.** Throw `HttpFailure` (or its named constructors) for
  client errors; unexpected exceptions become a bare 500 ([errors and observability](design/05-errors-and-observability.md)).

## Verify your work

Run these before you finish; they are the definition of done in Shirakaba projects:

- `vendor/bin/phpstan analyse` with `shirakaba/phpstan` included (wiring, safe methods, clocks,
  ORM names and types);
- the test suite (`TestClient`, `ConsoleTester`, `StatementLog` for N+1 checks);
- `app openapi --strict` when routes changed;
- `app db:diff --check` and `app db:lint` when entities or migrations changed.

To find out what the application declares instead of guessing, use `app routes`,
`app routes:show <name or "METHOD /path">`, `app entities` and `app entities:show <Entity>`
(add `--json` for structured output), when the application registers them.

Command names depend on how the application registers them; see
[guide/13-command-line.md](guide/13-command-line.md).
