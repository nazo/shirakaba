# Principles and project structure

Shirakaba is a small PHP web framework: a core that routes a request to a typed handler and
returns a response, and separate packages for everything else. This document describes the
rules every package follows and how the project is organised and distributed.

## A minimal core

`shirakaba/core` contains only what every application needs to receive a request, route it to
a handler and return a response:

- the application kernel, a PSR-15 `RequestHandlerInterface` running a PSR-15 middleware
  pipeline;
- the router ([routing and handlers](04-routing-and-handlers.md));
- handler invocation with typed argument extraction; extractors are a public interface, so
  packages add their own;
- error-to-response conversion with RFC 9457 Problem Details
  ([errors and observability](05-errors-and-observability.md)). The core writes this one JSON
  structure itself; general serialisation stays out;
- the request scope with cleanup, cancellation and deadlines
  ([runtimes and scopes](03-runtimes-and-scopes.md));
- an in-process test client that calls the kernel without a server;
- a small observer interface for logging and tracing packages.

The core does not emit responses through a SAPI, read superglobals, contain a container,
logging, validation, serialisation, OpenAPI generation, sessions, authentication, database
access, caching or templating. Each of these is a package the application adds on purpose.

The kernel reads no global state. Route planning and matching are pure; running a handler is
not, since handlers reach databases, streams and clocks, so execution is the effectful
boundary of the application. The same application object runs unchanged under every runtime,
and tests exercise the real application through the test client.

## Few dependencies

Every installed package is attack surface and supply-chain risk. Runtime dependencies of
Shirakaba packages are limited to:

1. other Shirakaba packages;
2. PSR interface packages (`psr/*`);
3. PHP extensions (`ext-*`);
4. a third-party library only when the package exists to integrate with it (the OpenTelemetry
   API in `shirakaba/opentelemetry`, for example). Such a library never reaches
   `shirakaba/core`.

Development tools (PHPStan, PHPUnit) are not restricted but kept to a small set, and
`composer audit` runs in CI. A package must not declare a dependency it does not use.

As a result Shirakaba implements several things itself that other frameworks take from
libraries: PSR-7 messages, routing, JWT verification, an SMTP client, a Redis client, a
template engine. That code is tested to the same standard as everything else, and integrations
with third-party systems are always opt-in packages.

## Configuration as code

YAML, XML, `.env` files and configuration arrays are invisible to static analysis, and
discovery mechanisms (directory scanning, package auto-discovery, service providers listed in
metadata) hide what an application contains. Therefore:

- there are no framework configuration files; an application is assembled in plain PHP;
- packages are added by explicit code, by instantiating a module or middleware and passing it
  to the application; nothing is enabled because it was installed;
- options are typed constructor parameters, preferably named arguments;
- there is no reflection-based autowiring. If an autowiring package is ever added, it
  generates plain PHP at build time instead of resolving by reflection on each request;
- environment variables are read explicitly through typed accessors.

PHPStan therefore sees the whole application graph, including option types and wiring, and IDE
navigation and refactoring work on configuration. Applications contain slightly more explicit
code than in convention-driven frameworks.

## Service wiring without a container

Handlers grouped in a class with their dependencies in its constructor, created once in a
builder function and registered with first-class callables (`$handlers->show(...)`), need no
container. The recommended style is a function or static method that takes the application's
infrastructure (repositories, clients, configuration) and returns the `AppBuilder`. The same
function serves the runtime, the tests (with other implementations) and tools such as the
OpenAPI generator, so swapping an implementation is a constructor argument.

Handler objects are application-scoped: they hold only immutable dependencies. Per-request
services are created by middleware or extractors and placed in the `RequestScope`, released
with `defer()`. Application services that request-scoped classes need are placed in the scope
by the `Provide` middleware ([database](08-database.md)).

A container is reconsidered only when explicit wiring becomes the bottleneck: many independent
modules each needing the same infrastructure, or wiring duplicated across entry points despite
a shared builder function. If one is built, it generates plain PHP at build time and keeps
application and request lifetimes distinct.

## PHP version

The minimum is PHP 8.5 in every package. Recent releases remove whole classes of framework
code and bugs: property hooks, asymmetric visibility and lazy objects (8.4); the built-in URI
extension, the pipe operator, `clone()` with property overrides, `#[\NoDiscard]` and closures
in constant expressions (8.5). The PSR-7 URI implementation builds on the URI extension, and
immutable `with*()` methods are marked `#[\NoDiscard]`. `phpstan.dist.neon` pins `phpVersion`
to the minimum, and CI runs it. Raising the minimum is allowed when a new release offers
something worth it.

## One repository, many packages

All packages live in this repository under `packages/<name>/`, each with its own
`composer.json` named `shirakaba/<name>` and the namespace `Shirakaba\<Name>\`. Keeping them
together guarantees that they are compatible: one CI run tests every package against every
other at the same commit.

- The root `composer.json` is a development-only project. It `replace`s every package, maps
  every package's autoload sections and requires the union of their external dependencies.
- All packages share one version line, expressed by the root `extra.branch-alias.dev-main`;
  internal dependencies use the matching `^<major>.<minor>` constraint.
- `tools/monorepo/bin/validate` (run by `composer check`) enforces these rules, and
  `composer monorepo:standalone` installs, analyses and tests each package on its own, which
  catches a dependency a package uses but does not declare.
- The database packages depend on no framework package; their framework integration is a
  separate bridge package ([database](08-database.md)).

## Distribution

Until the first release under its final name, the packages are not on Packagist. A GitHub
Actions workflow builds a static Composer repository from this repository and publishes it on
GitHub Pages; applications add one entry:

```json
"repositories": [{"type": "composer", "url": "https://nazo.github.io/shirakaba/"}]
```

- `tools/repository/bin/build` writes `packages.json`, one zip per package and version under
  `dist/`, and an index page. Each package keeps its own `composer.json`, so applications
  install `shirakaba/core` and the others under their usual names and paths, commands included.
- Every tag `vX.Y.Z` (with an optional pre-release suffix) becomes version `X.Y.Z` of every
  package at that commit, and `main` becomes `dev-main` with the packages' branch alias. The
  site is rebuilt from all tags whenever CI has passed on `main` and whenever a tag is pushed, so
  old releases stay available and a `dev-main` that fails its checks is never published.
- Archives are made with `git archive` and the commit time as the time of every entry, so a
  rebuild produces the same files and checksums, and lock files stay valid. A `dev-main`
  archive disappears when `main` moves on; applications that need a fixed state use a tag.
- The URL is not written into the repository: the workflow takes it from GitHub Pages, so
  renaming the repository only changes the line applications add.
- The packages have no source (git) form, so `--prefer-source` is not available.
- Every package carries its own copy of the `LICENSE` file, because each is distributed on its own;
  the monorepo validator checks that the copies match the root's.

Read-only split repositories per package and Packagist are planned for the release under the
final name; moving to Packagist then means removing the `repositories` entry.
