# Working on Shirakaba

Shirakaba is a PHP 8.5 web framework: a small, PSR-compliant core with everything else in
separate Composer packages, configured in PHP, and checked by PHPStan at level max. It is
not in your training data; read the code and docs here instead of assuming it behaves like
Laravel, Symfony or Slim. It has not been released, so incompatible changes are fine when
they make the design better.

## Layout

| Path | What it is |
| --- | --- |
| `packages/*` | One Composer package per directory (`shirakaba/core`, `shirakaba/orm`, ...), each with `src/` and `tests/` |
| `packages/docs/` | `shirakaba/docs`: the guide, the design documents, `index.md` (the entry point for agents in applications) and the generated `api.md` |
| `packages/docs/design/` | Design documents by area; read the relevant one before changing a design |
| `packages/docs/guide/` | The user guide |
| `docs/roadmap.md` | What exists and what is planned |
| `docs/benchmarks/`, `docs/research/` | Benchmark results and research notes |
| `examples/` | Example applications, tested with the packages |
| `tests/conformance/` | Cross-package conformance tests |
| `tools/monorepo/` | The monorepo validator and the standalone package runner |
| `tools/docs/` | The generator for `packages/docs/api.md`, `llms.txt` and `llms-full.txt` |
| `tools/repository/` | The builder of the Composer repository published on GitHub Pages ([principles](packages/docs/design/01-principles.md)) |
| `tools/databases/` | The Docker setup behind `composer test:databases` |
| `tools/evals/` | Agent evaluation tasks and their runner; `composer evals:reference` checks the tasks |

The root `composer.json` replaces every package and autoloads them all; a new package needs
its `replace` and `autoload` entries there, and `composer monorepo:validate` says what is missing.

## Definition of done

Run both before saying a change is finished:

- `composer check`: the monorepo validator, PHPStan over everything, and PHPUnit.
- `composer monorepo:standalone`: installs, analyses and tests each package on its own, which
  catches a dependency a package uses but does not declare.

`composer steins` runs Steins ([static analysis and agents](packages/docs/design/13-static-analysis-and-agents.md)); CI runs it without blocking, but keep it free of errors.

When public classes or docs change, run `composer docs:generate` to regenerate
`packages/docs/api.md`, `llms.txt` and `llms-full.txt`; a
test fails while it is stale.

Database tests run against SQLite by default. MySQL and PostgreSQL suites run when
`SHIRAKABA_TEST_MYSQL_DSN` or `SHIRAKABA_TEST_PGSQL_DSN` is set, with `_USER` and `_PASSWORD`
alongside. `composer test:databases [phpunit arguments]` (`tools/databases/test.sh`) does all of
it in Docker: it builds a PHP image with the PDO drivers, starts mysql:8.4 and postgres:17 if
they are not running, runs PHPUnit against all three databases and stops what it started.
Changes to SQL generation, connections or the ORM should be run this way.

The same command starts Redis and Mailpit. The Redis client, the Redis queue and the Redis
session store are tested when `SHIRAKABA_TEST_REDIS_URL` is set (`redis://:password@host:6379/1`),
and the mailer sends a real mail when `SHIRAKABA_TEST_SMTP_DSN` points at Mailpit
(`smtp://host:1025?tls=none`) and `SHIRAKABA_TEST_MAILPIT_API` at its API (`http://host:8025`);
without them these tests are skipped. CI sets all of them.

FrankenPHP tests (the runtime conformance suite in worker mode, and the Mercure hub test of
shirakaba/pubsub) run when `FRANKENPHP_BINARY` points at a FrankenPHP binary (a release from
github.com/php/frankenphp) and are skipped otherwise. The RoadRunner conformance tests run when
`rr` is on the PATH or `RR_BINARY` names it (a release from github.com/roadrunner-server/roadrunner),
and the Swoole ones when the Swoole extension is loaded or `SWOOLE_PHP_BINARY` names a PHP binary
that has it.

## Conventions

- Everything in files is English: code, comments, docs, commit messages.
- No `@phpstan-ignore` and no baseline. Fix the type, or narrow it with a check.
- A design change updates the relevant document in `packages/docs/design/` in the same commit
  as the code, so the documents always describe the current design and its reasons. Code
  comments point at a document by its path (`design/08-database.md`), never at a work item.
- Planned work is listed in `docs/roadmap.md`; move an item to "Available" when it is done.
- Commit only the files you changed (`git add <paths>`, not `git add -A`), since other work
  may be in progress in the same checkout.
- No global state, and nothing that leaks between requests in long-running runtimes
  ([runtimes and scopes](packages/docs/design/03-runtimes-and-scopes.md)); time comes from a PSR-20 clock.
- Public API lives outside `Internal` namespaces; anything in `Internal` or marked
  `@internal` may change without notice.
