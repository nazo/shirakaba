# Benchmark baseline

Recorded 2026-11-10 with `composer bench`. Median of five rounds of about
200 ms each, after a warm-up. Times are per operation and comparable only on the same
machine and PHP build.

- Machine: Apple Silicon (arm64), macOS
- PHP 8.5.12 CLI, opcache on (`opcache.enable_cli=1`), JIT off, assertions off

| Scenario                                            | µs/op |
|-----------------------------------------------------|------:|
| Minimal request (one route, Text)                   |   4.3 |
| Routing: last of 300 routes, #[Path] int            |  21.7 |
| Routing: 404 among 300 routes (Problem Details)     |  26.2 |
| Extraction: three query parameters                  |   6.3 |
| JSON: nested DTO with constraints, JSON response    |  17.3 |
| Build: example task API (per request under PHP-FPM) |  66.2 |
| Build: 300 routes                                   | 1538.0 |

## Reading

- Handling itself is cheap: a request with typed extraction or a validated JSON body
  costs tens of microseconds, small next to any I/O.
- Routing is linear in the number of route shapes ([routing and handlers](../../packages/docs/design/04-routing-and-handlers.md)): with 300 routes the last
  match and a 404 cost about 20 µs more than a single route.
- Building dominates under PHP-FPM, where the application is built for every request:
  1.5 ms for 300 routes, mostly handler reflection. This, not matching, is the first
  optimisation target: cache the route table and handler plans across requests (the
  compiled form anticipated by [routing and handlers](../../packages/docs/design/04-routing-and-handlers.md)), and keep worker runtimes as the recommended
  deployment for large applications.

## Builds without descriptions

`build()` stopped producing descriptions (only `describe()` needs them):

| Scenario | Before (µs) | After (µs) |
|---|---:|---:|
| Build: 300 routes | 1538.0 | 1337.0 |

The example's build time is not comparable across the two runs: the example gained routes
and database middleware in between.

## Lazy planning instead of a cache

Caching plans with `serialize()` was measured and rejected: restoring 300 routes cost
1036 µs against 1245 µs for planning them, because `unserialize()` is about as expensive as
planning (300 path templates: 200 µs to parse, 204 µs to unserialize). Instead:

- routes are ordered by precomputed precedence keys instead of pairwise comparisons;
- `build(lazy: true)` plans a handler only when its route is requested.

| Scenario | µs/op |
|---|---:|
| Build: example task API (eager) | 104.6 |
| Build: example task API, lazy | 12.6 |
| Lazy build + one request (example) | 88.6 |
| Build: 300 routes (eager, after the sort change; was 1319.9) | 1164.0 |
| Build: 300 routes, lazy | 700.6 |

With 300 routes, the rest of a lazy build is parsing templates (about 200 µs) and building
the router; a compiled route table ([routing and handlers](../../packages/docs/design/04-routing-and-handlers.md)) remains the next step if that matters.

## ORM hydration (2026-11-11)

| Scenario | µs/op |
|---|---:|
| 100 `Post` entities from SQLite (enum, JSON, two instants, value object) | 557.4 |
| The same 100 rows as arrays (`Select::fetchAll`) | 78.6 |

About 4.8 µs per entity on top of fetching. Parsing instants is the largest part (about
0.9 µs each); a fast path for the exact format Shirakaba writes is the first optimisation if
it matters. Mapping metadata is planned once per query, never per row.

After the fast path for instants in Shirakaba's own formats, the same run gives
344.3 µs for 100 entities and 58.3 µs for the arrays: about 2.9 µs per entity. Parsing an
instant dropped from about 0.94 to 0.49 µs.

