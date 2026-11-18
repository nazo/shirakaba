# Shirakaba

A minimal PHP web application framework built from small, independent packages.

> Shirakaba is in early development and is not released. APIs change without notice.

## Installation

The packages are not on Packagist yet. Add the Shirakaba repository to your application's
`composer.json` ([principles](packages/docs/design/01-principles.md)), then require the packages you need:

```json
"repositories": [{"type": "composer", "url": "https://nazo.github.io/shirakaba/"}]
```

```sh
composer require shirakaba/core shirakaba/runtime-fpm
```

Tagged versions are listed at the repository URL; `dev-main` follows the `main` branch
and needs `"minimum-stability": "dev"`.

## Principles

- **Minimal core.** `shirakaba/core` routes a PSR-7 request to a handler and returns a
  response. Everything else is an opt-in package.
- **Few dependencies.** Runtime dependencies are limited to Shirakaba packages, PSR
  interfaces and PHP extensions.
- **Configuration as code.** No configuration files and no auto-discovery. An
  application is plain PHP that static analysis can check.
- **Safe in long-running processes.** The same application runs on PHP-FPM, in FrankenPHP
  worker mode, on RoadRunner and on Swoole through runtime packages, with nothing leaking
  between requests.
- **Statically verifiable.** PHPStan at level `max`, with effect annotations prepared
  for [Steins](https://github.com/rigortype/steins).

See the [guide](packages/docs/guide/README.md) to build an application,
[the design documents](packages/docs/design/README.md) for the reasons behind it,
and [docs/roadmap.md](docs/roadmap.md) for what exists and what is planned. [AGENTS.md](AGENTS.md)
has the checks and conventions for working on Shirakaba itself.

Applications can point their coding agents at the documentation for the installed version
with `composer require --dev shirakaba/docs` and `vendor/bin/shirakaba-docs agents:sync`. The same
documents are available as [llms.txt](packages/docs/llms.txt) and
[llms-full.txt](packages/docs/llms-full.txt) for tools that read them.

## Example

```php
<?php // public/index.php

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\Text;
use Shirakaba\Runtime\Fpm\FpmRuntime;

$app = new AppBuilder();
$app->get('/users/{id}', static fn (#[Path] int $id, #[Query] bool $verbose = false): Text
    => new Text(sprintf('user %d (verbose: %s)', $id, $verbose ? 'yes' : 'no')));

new FpmRuntime()->run($app->build());
```

With `shirakaba/json` and `shirakaba/validation`, request bodies map into DTOs:

```php
final readonly class CreateUser
{
    public function __construct(
        #[Length(min: 1, max: 50)] public string $name,
        #[Email] public string $email,
    ) {}
}

$app->post('/users', static fn (#[JsonBody] CreateUser $input): Json => new Json(['name' => $input->name], 201));
```

and `vendor/bin/shirakaba-openapi` writes an OpenAPI document from the same declarations.

`build()` checks every route and handler signature before the first request. Invalid
input becomes an RFC 9457 Problem Details response (`/users/abc` is a 400), and
unmatched methods get a 405 with `Allow`.

## Packages

| Package | Contents |
|---|---|
| `shirakaba/http-message` | PSR-7 messages and PSR-17 factories |
| `shirakaba/core` | Application builder, router, typed argument extraction, Problem Details, request scope, observer interface, test client |
| `shirakaba/sapi` | Builds PSR-7 requests from SAPI superglobals and emits responses; shared by runtimes |
| `shirakaba/runtime-fpm` | Runs an application under PHP-FPM and other classic SAPIs |
| `shirakaba/runtime-frankenphp` | Runs an application in FrankenPHP worker mode |
| `shirakaba/runtime-roadrunner` | Runs an application as a RoadRunner HTTP worker |
| `shirakaba/runtime-swoole` | Runs an application on a Swoole HTTP server |
| `shirakaba/streaming` | Streamed bodies and Server-Sent Events |
| `shirakaba/form` | HTML forms and file uploads mapped to DTOs |
| `shirakaba/session` | Worker-safe sessions, secure cookies, flash data, CSRF protection |
| `shirakaba/auth` | Bearer authentication and minimal JWT verification |
| `shirakaba/database` | PDO with pooled, leased connections, reader/writer capabilities, and dialects for MySQL, PostgreSQL and SQLite; usable without the framework |
| `shirakaba/database-bridge` | Connects the database packages to the framework: per-request connections, `#[Db]`, observer events, 404/409 for entity errors, entity and migration commands |
| `shirakaba/query` | Immutable query builder with validated identifiers and bound values |
| `shirakaba/orm` | Readonly entities, explicit writes, relations loaded into projections, scopes |
| `shirakaba/console` | Typed commands, help, exit codes and signal-driven cancellation |
| `shirakaba/queue-redis` | A Redis driver for the queue: pushed after commit, leased with tokens |
| `shirakaba/queue` | Background jobs with a transactional database queue, leases and retries |
| `shirakaba/pubsub` | Publish/subscribe with transactional publishing, on PostgreSQL LISTEN/NOTIFY or in memory |
| `shirakaba/http-client` | A PSR-18 client with timeouts, size limits, checked redirects and SSRF protection on resolved addresses |
| `shirakaba/rate-limit` | Rate limits per route or per call, in fixed windows stored in the database, with 429 and RateLimit headers |
| `shirakaba/migrations` | Versioned schema migrations, schema builder, lint (the CLI comes with the bridge) |
| `shirakaba/query-cache` | Opt-in query result caching over PSR-16 |
| `shirakaba/template` | A small template engine for mail: data from readonly objects, escaping by file type, no code in templates, checks against the data class |
| `shirakaba/mailer` | Mail: an `Email` value, a MIME writer, an SMTP transport with required TLS, templated mail, test mailers |
| `shirakaba/mailer-queue` | Mail sent through the queue after the transaction commits, with retries |
| `shirakaba/session-database` | Sessions in the application database, shared by every instance |
| `shirakaba/session-redis` | Sessions in Redis, shared by every instance and expired by Redis |
| `shirakaba/redis` | A small Redis client: RESP over a socket with TLS, AUTH, timeouts and Lua scripts, without extensions |
| `shirakaba/time` | PSR-20 clocks, strict RFC 3339 parsing, `LocalDate` |
| `shirakaba/log` | Logs errors and requests to a PSR-3 logger |
| `shirakaba/opentelemetry` | One OpenTelemetry span per request |
| `shirakaba/phpstan` | PHPStan rules: long-running safety, no writes in GET/HEAD handlers, explicit clocks, ORM queries |
| `shirakaba/cors` | CORS middleware |
| `shirakaba/trusted-proxy` | Forwarding headers from trusted reverse proxies, allowed hosts, canonical origin |
| `shirakaba/json` | JSON request bodies mapped to DTOs, JSON responses |
| `shirakaba/validation` | Constraint attributes for parameters and DTO fields |
| `shirakaba/openapi` | OpenAPI 3.1 documents generated ahead of time from route metadata |
| `shirakaba/docs` | The guide, design documents and API index for coding agents, with `agents:sync` |

## Requirements

PHP 8.5 or later.

## Development

```sh
composer install
composer check                # monorepo validation, PHPStan and PHPUnit
composer monorepo:standalone  # install and test each package on its own
```

Repository layout:

| Path | Contents |
|---|---|
| `packages/<name>/` | One Composer package `shirakaba/<name>` per directory |
| `tools/monorepo/` | Development tooling for the monorepo itself |
| `tools/repository/` | The builder of the Composer repository on GitHub Pages ([principles](packages/docs/design/01-principles.md)) |
| `examples/tasks/` | Example task API using most packages |
| `tests/conformance/` | Runtime conformance suite over real HTTP |
| `packages/docs/` | `shirakaba/docs`: the guide, design documents and API index, installed with applications for coding agents ([static analysis and agents](packages/docs/design/13-static-analysis-and-agents.md)) |
| `docs/` | The roadmap, benchmark results and research notes |

## Contributing

Issues are welcome; pull requests from outside are not accepted for now. See
[CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md) for reporting vulnerabilities.

## License

[MIT](LICENSE). Third-party files used by tests are listed in the `NOTICE.md` next to them.
