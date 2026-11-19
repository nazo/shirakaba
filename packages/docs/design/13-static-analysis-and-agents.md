# Static analysis and coding agents

## PHPStan at level max

All code passes PHPStan at level `max` with strict rules, bleeding edge (the checks of the next
major version) and the extra strictness options in `phpstan.common.neon`. Baselines and
`@phpstan-ignore` are not used: a type is fixed or narrowed with a check. Configuration as code
([principles](01-principles.md)) exists largely so that PHPStan sees the whole application.

`reportUnsafeArrayStringKeyCasting` is not enabled yet. It makes `array<string, X>` mean keys that PHP
does not cast to integers, but PHPStan cannot narrow a `string` to such a key with any check, so
every map keyed by a column, header or field name would need a checked conversion at its boundary.

The monorepo analysis also finds unused code with `shipmonk/dead-code-detector`. Public and
protected members of package classes outside `Internal` and `@internal` are public API, and
properties of classes in tests and examples are read by the framework through reflection;
`tools/phpstan/src/PublicApiUsageProvider.php` tells the detector so. Members that only tests use are
reported. A member of an internal class that something outside PHP reads, such as a property that
templates read, is marked `@api`.

`shirakaba/phpstan` adds rules for applications:

| Rule | Reports |
|---|---|
| `SafeMethodHandlerRule` | a GET or HEAD handler that takes a `Writer` or a class built from one |
| `NoSharedMutableStateRule` | mutable static properties and global variables in package code |
| `NoStateInAppScopedObjectRule` | request data kept in properties of middleware, handlers or extractors |
| `NoAmbientClockRule` | reads of the wall clock other than a PSR-20 clock |
| `EntityQueryRule` | unknown columns and relations, and value types that do not match the mapping |
| `RequiredScopeRule` | a query of a scoped entity that cannot have its required scope |
| `QueryInLoopRule` | queries inside loops, the usual source of N+1 queries |

`SelectResultTypeExtension` gives query results their row shapes.

## Other checks

- **Dependencies.** `composer monorepo:standalone` runs `shipmonk/composer-dependency-analyser` in
  each installed package: a dependency used but not declared (extensions included), declared but
  unused, or in the wrong section fails. Dependencies in `suggest` are optional and used behind a
  check; a package lists its other exceptions, with reasons, in
  `composer-dependency-analyser.package.php`.
- **Mutation testing.** `composer infection` runs Infection over the packages that guard security
  boundaries (authentication, CORS, the SSRF-safe HTTP client, rate limits, sessions and CSRF,
  trusted proxies). CI runs it as a non-blocking job: surviving mutants show where tests are weak,
  and the score is not a gate.
- **Formatting.** Mago formats the code (`mago.toml`, `composer format`); only its formatter is used.
  `composer check` fails while a file is not formatted.

## Effects and Steins

[Steins](https://github.com/rigortype/steins) is a PHP type checker modelled on PHPStan that adds an
effect system: it infers what a function can do to the outside world (`io.db`, `io.net.http`,
`nondet.time`, ...) and checks it against declared upper bounds called envelopes. It reads PHPStan's
purity tags, including `@phpstan-impure <label>`.

- Pure code is marked `@phpstan-pure`; code with known effects is marked `@phpstan-impure <label>`
  using Steins' label vocabulary (`io`, `io.db`, `io.output`, `global.read`, `nondet.time`,
  `nondet.random`). The tags are useful to PHPStan today and become effect envelopes under Steins.
- Only PHPDoc tags are used: no Steins attributes and no runtime change. Generic attribute scanners in
  applications that instantiate every attribute would fail when the attribute class is absent, and
  requiring it would force a pre-release dependency on users.
- `composer steins` runs a pinned Steins version over the packages with the strict profile. CI runs it
  as a non-blocking job. Envelopes on public extension points are added only where the bound is
  certain, since a wrong bound becomes a breaking change for implementers once it is enforced.

The guarantee is stated honestly. The goal is to prove that handlers for safe methods (GET, HEAD) do
not write to a database, but Steins cannot show it yet: `io.db` has no read and write sub-labels, PDO
is not in its catalogue, and handlers registered as closures are unknown callees. Until it can, the
`Reader`/`Writer` split checked by `SafeMethodHandlerRule` is the enforcement, which is weaker than an
effect proof. Observation, logging and cache writes are allowed effects even for safe methods; the
policy covers application writes only. "Statically verifiable" is never claimed beyond what is proven.

## Support for coding agents

Coding agents write much application code, and Shirakaba is not in any model's training data: an
agent left alone guesses its API from other frameworks and gets it wrong. Shirakaba already gives
agents a lot to check against (PHPStan rules, `describe()`, OpenAPI generation, schema checks,
migration lint and diff, test helpers); what they lack is knowledge of Shirakaba itself. The approach
follows the one Next.js documents for the same problem.

- **Version-matched documentation ships as one package, `shirakaba/docs`**, installed as
  `vendor/shirakaba/docs/`: the guide, these design documents, an API index, error pages and problem
  code pages. Its version always matches the framework's, because all packages are released together.
  One package was chosen over docs in each package because the guide crosses packages.
- **`AGENTS.md` is updated only by an explicit command**, `shirakaba-docs agents:sync`, never by
  Composer hooks or at runtime. It writes a block between markers, keeps everything else, and tells
  agents that Shirakaba is not in their training data and where to read. `--claude` also makes
  `CLAUDE.md` import `AGENTS.md` for agents that read only that file.
- **Commands instead of a server.** Shirakaba has no long-running development server, and what an agent
  needs to inspect can be printed: routes, entities, schema checks, migrations and OpenAPI are console
  commands with plain-text and JSON output. An MCP server would be reconsidered only for a need that
  commands cannot meet.
- **Errors carry codes and fixes.** Build-time errors and Problem Details have stable codes with a page
  each that lists the fixes and their trade-offs; messages end with the path of that page.
- **Online formats come from the same source.** `llms.txt` and `llms-full.txt` are generated from
  `shirakaba/docs` by `composer docs:generate`, and a test fails while they are stale.
- **Skills only for workflows** that span several steps (adding an endpoint, adding an entity with its
  migration, auditing N+1 queries, adding a background job); facts belong in the documentation.
- **Effects are measured.** `tools/evals/` holds agent tasks on a fresh application with checks that
  decide success, run with and without the bundled documentation.

Documentation is part of every change's definition of done: an API change updates `shirakaba/docs` in
the same commit.
