# ORM and database layer survey (2026-11)

Input for the ORM work in Shirakaba, targeting MySQL, PostgreSQL and SQLite. It records what
current libraries in several ecosystems do, the trends across them, and what that suggests
for Shirakaba. It is not a decision; the database design document follows from it.

## Libraries surveyed

| Ecosystem | Library | Style | Notable recent direction |
|---|---|---|---|
| TypeScript | Prisma 7 | schema file + generated client | Rust engine removed: a TypeScript query compiler turns queries into a plan run through JS drivers ("driver adapters"); serialising across Rust/JS cost more than Rust saved. TypedSQL: `.sql` files checked at build time, types generated. |
| TypeScript | Drizzle (v1 RC) | schema in code, SQL-shaped builder | Relational Queries v2 with one `defineRelations()` place for all relations; nested `with:` loads compile to one SQL statement (lateral joins + JSON aggregation on PostgreSQL, correlated subqueries + `json_group_array` on SQLite). |
| TypeScript | Kysely | typed query builder only | No entities, no relations; the database interface type checks table and column names in every query. |
| Python | SQLAlchemy 2.0/2.1 | Core (SQL expression) + ORM (data mapper, unit of work) | 2.0 unified on `select()` for Core and ORM, `Mapped[...]` annotations drive mapping and typing; 2.1 types result rows with PEP 646 (`Select[int, str]`). |
| Java | Hibernate 7 / Jakarta Data | JPA + repositories | Repository interfaces implemented by an annotation processor; queries validated at compile time; repositories run on a `StatelessSession` (no persistence context, no dirty checking). New type-safe criteria API on the static metamodel. |
| .NET | EF Core 10 | LINQ + change tracking | Complex types (value objects) mapped to columns or one JSON column, queryable and updatable in bulk; named global query filters (soft delete, tenant) that can be disabled one by one. |
| Rust | SeaORM 2.0, Diesel, sqlx | ORM / compile-checked DSL / checked raw SQL | SeaORM 2.0 (2026-07): any model usable in nested selects into partial models. sqlx checks SQL strings against a live database at compile time, with an offline cache for CI. |
| Go | sqlc, Ent, Bun | SQL-first codegen / schema-first codegen / builder | Strong SQL-first current: write SQL, generate typed functions (sqlc). GORM-style reflection ORMs are losing favour on speed and opacity. |
| Kotlin | Exposed 1.0 (2026-01) | typed SQL DSL + optional DAO | Stable API, core split from the JDBC layer so an R2DBC (async) driver could be added. |
| Elixir | Ecto | repository + changesets | No lazy loading by design; associations are preloaded explicitly; changesets separate validation and change tracking from structs. |
| PHP | Doctrine ORM 3.4+ | data mapper, unit of work | PHP 8.4 native lazy objects replace generated proxies (opt-in in 3.x, required in 4.0); property hooks supported. |
| PHP | Laravel Eloquent 12 | active record | `automaticallyEagerLoadRelationships()` (12.8) batches relation loads on first access, to stop N+1 without explicit `with()`; criticised as hiding queries. |
| PHP | Tempest 3 | decoupled models + builder | Plain typed classes mapped to tables; MySQL, PostgreSQL, SQLite; relationships, migrations. |
| Schema tooling | Atlas | schema as code | Declarative (state-based) migrations: diff desired schema vs database, plan, lint destructive changes in CI, then apply. |

PHP 8.4 also added driver subclasses (`Pdo\Mysql`, `Pdo\Pgsql`, `Pdo\Sqlite`) and
`PDO::connect()`, which returns the right subclass; dialect-specific APIs no longer need
`getAttribute(PDO::ATTR_DRIVER_NAME)` checks.

## Trends

1. **SQL is the interface again.** Kysely, Drizzle, sqlc, sqlx, Prisma TypedSQL, jOOQ and
   SQLAlchemy Core all keep SQL's shape visible. Query languages of their own (DQL, HQL)
   and builders that hide joins are in retreat. Libraries offer an escape hatch to plain
   SQL with typed results rather than forcing everything through a builder.
2. **Types from one source, checked before runtime.** Whether the source is the schema
   (Prisma, Ent), code annotations (SQLAlchemy `Mapped`, Drizzle tables) or the SQL
   itself (sqlc, sqlx, TypedSQL), column names, parameter types and result row types are
   checked by the compiler or a build step. In PHP the equivalent is PHPStan.
3. **Explicit loading, no hidden queries.** Ecto never lazy loads; Hibernate's
   repositories use stateless sessions; Drizzle and Prisma load nested relations in one
   query or a fixed number of queries. Laravel's automatic eager loading is the
   counter-example, and its reception shows the cost of implicit queries.
4. **Nested results in one round trip.** JSON aggregation (`json_agg`/`json_object` in
   PostgreSQL and MySQL, `json_group_array` in SQLite) or lateral joins return object
   graphs without N+1 queries or cartesian blow-up from joins.
5. **Lighter persistence models.** Unit of work with identity maps and dirty checking
   (Doctrine, Hibernate sessions, EF tracking) is increasingly optional: Jakarta Data's
   stateless repositories, EF's no-tracking queries, Ecto's changesets, Drizzle/Kysely
   with explicit `insert`/`update`. Writes are explicit statements.
6. **Value objects and JSON columns as first-class mapping.** EF complex types, embedded
   types elsewhere: a property can be a value object spread over columns or stored as
   JSON, and still be queried.
7. **Plain objects, not base classes.** Entities are typed classes (often readonly or
   immutable) with mapping by attributes or a separate schema, not active-record base
   classes. PHP 8.4 lazy objects let a mapper defer loading without proxy classes.
8. **Driver layer separate from query layer.** Prisma's driver adapters, Exposed's
   JDBC/R2DBC split, Kysely dialects: the part that talks to the database is replaceable,
   which also enables async drivers and serverless/edge connections.
9. **Schema as code with reviewed migrations.** Declarative schema, generated migration
   diffs, linting for destructive changes, and a plan/review step before applying.
10. **Cross-cutting filters declared once.** Soft delete and tenant scoping as named,
    individually disableable filters (EF Core 10) rather than ad-hoc where clauses.

## What this suggests for Shirakaba

These are starting points for the design, not decisions.

- **Layering.** Keep `shirakaba/database` (connections, `Reader`/`Writer`, transactions,
  [database](../../packages/docs/design/08-database.md)) as the driver layer. Add a typed query builder over it, then a thin mapper
  over the builder; each usable without the next, as with SQLAlchemy Core/ORM or
  Kysely/Drizzle.
- **Dialects.** One dialect object per database (MySQL, PostgreSQL, SQLite) owning
  quoting, placeholders, `RETURNING`/`LAST_INSERT_ID`, upsert syntax, JSON functions and
  limit/offset. Use `PDO::connect()` and the PHP 8.4 subclasses.
- **Entities.** Plain `final readonly` classes with constructor promotion, mapped by
  attributes (as `#[JsonDto]` is), hydrated through the constructor. No base class, no
  identity map, no dirty checking: writes are explicit `insert`/`update`/`delete` calls,
  which also suits long-running runtimes where shared state leaks between requests.
- **Relations.** Declared on the entity but never lazy loaded; loading is requested per
  query (`with(...)`), executed as one batched `IN` query per relation (works on all three
  databases) or JSON aggregation where supported. An unloaded relation is a distinct
  state that PHPStan can see, not a hidden query.
- **Types.** A PHPStan extension that knows the mapping: column names in builder calls,
  parameter types, and result row shapes. Raw SQL keeps working through `Reader`/`Writer`
  with typed row mapping into DTOs.
- **Value objects and JSON columns.** Embedded value objects over several columns and
  JSON columns mapped to DTOs, reusing the JSON package's mapping rules.
- **Filters.** Named default scopes (soft delete, tenant) that a query can switch off by
  name.
- **Migrations and schema.** Start with versioned PHP migrations and a schema description
  derived from entities; a diff command can come later. Treat declarative diffing (Atlas
  style) as a separate, later package.
- **Read/write split.** Builder entry points follow `Reader` and `Writer`, so the PHPStan
  rule for safe methods ([static analysis and agents](../../packages/docs/design/13-static-analysis-and-agents.md)) keeps working.

## N+1 queries and caching

### N+1

Four approaches, often combined:

| Approach | Examples | Notes |
|---|---|---|
| No lazy loading at all | Ecto, Drizzle, Kysely, Prisma | An unloaded association is a placeholder (`Ecto.Association.NotLoaded`) or simply absent from the result type; loads are requested per query. |
| Explicit eager loading, chosen per query | SQLAlchemy `selectinload`/`joinedload`, Doctrine fetch joins, Laravel `with()`, EF `Include` | `selectinload` (one `IN` query per relation) is the usual recommendation; joins duplicate parent rows. |
| Lazy loading allowed but guarded | Rails `strict_loading` (`:all` raises, `:n_plus_one_only` raises only for loads that would repeat per record), Laravel `preventLazyLoading()` with a violation handler, SQLAlchemy `raiseload` | Commonly raise in development and tests, log in production. Laravel's guard only arms for models from multi-row results. |
| Automatic batching | Laravel 12.8 `automaticallyEagerLoadRelationships()`, GraphQL DataLoader | The first access loads the relation for every sibling record at once. Removes N+1 without code changes but hides when and what is queried. |

The libraries with the least N+1 trouble are those where a hidden query cannot happen:
the type of an unloaded relation differs from a loaded one, or the relation is not a
property at all until requested. Nested loads are executed either as one query per
relation (`WHERE parent_id IN (...)`, works everywhere) or as one statement with JSON
aggregation or lateral joins (Drizzle, Prisma on some databases).

### Caching

- **Per-session identity map.** Doctrine's unit of work, Hibernate's persistence context,
  EF's change tracker return the same object for the same row within one session. It is
  a correctness feature (one object per row) more than a cache, and needs a session that
  is reset per request.
- **Second-level / entity caches.** Hibernate's second-level cache and Doctrine's
  (PSR-6 since ORM 3, still documented as experimental) share entities between sessions.
  The query cache stores only IDs and falls back to one lookup per missing entity, so it
  can create N+1 of its own; Hibernate's own guidance is to benchmark before enabling it.
  Suitable mainly for read-mostly reference data.
- **Opt-in query result caches.** Drizzle (0.44+) caches only queries marked
  `$withCache()` (or all, with `global: true`), keyed by SQL, with automatic invalidation
  by the tables a query reads and manual invalidation by tag; TTL bounds staleness when
  automatic invalidation is off. Prisma Accelerate offered per-query `ttl`/`swr`/`tags`
  through a hosted proxy and is being retired on 2026-12-01.
- **Database-side.** Read replicas, connection poolers and materialised views are the
  usual answer at scale rather than ORM caches.

The common direction: no caching by default ("every query goes to the database", in
Drizzle's words), caching opt-in per query with explicit invalidation, and application
caches (Redis, PSR-6/16) preferred over transparent entity caches whose consistency is
hard to reason about.

### Implications for Shirakaba

- Relations are never lazy loaded. A relation property's type says whether it was loaded,
  so PHPStan reports reading one that was not requested; there is no runtime guard to
  configure and nothing to switch off in production.
- `with()` loads each relation with one `IN` query per level by default, on all three
  databases. JSON-aggregated single statements can be a later optimisation.
- Raw queries over `Reader` stay one query per call; looping over them is visible in code
  and can be flagged by query counting in tests (see below).
- No identity map or entity cache in the mapper: requests in long-running runtimes must
  not share entity state ([runtimes and scopes](../../packages/docs/design/03-runtimes-and-scopes.md)), and readonly entities do not need one object per row.
- Result caching, if added, is an explicit decorator over a query (`cached(ttl, tags)`)
  using PSR-16, with invalidation by tag issued from `Writer` operations, in its own
  package.
- Observability: the database package reports statement counts per request to observers
  ([errors and observability](../../packages/docs/design/05-errors-and-observability.md)), and the test client can assert a maximum, which catches N+1 in raw SQL as
  well.

## Sources

- Prisma: [Prisma 7 AMA](https://www.prisma.io/blog/prisma-7-ama-clearing-up-the-why-behind-the-changes),
  [engines docs](https://www.prisma.io/docs/v6/orm/more/internals/engines),
  [Rust-free benchmarks](https://www.prisma.io/blog/prisma-orm-without-rust-latest-performance-benchmarks),
  [changelog 2025-09-10](https://www.prisma.io/changelog/2025-09-10)
- Drizzle: [Relational Queries v2 migration](https://orm.drizzle.team/docs/mysql/relations-v1-v2),
  [v0 to v1 changes](https://orm.drizzle.team/docs/v0-v1-changes),
  [v0.28.0 release notes](https://orm.drizzle.team/docs/latest-releases/drizzle-orm-v0280),
  [generated SQL write-up](https://dev.to/hirodeath/drizzles-with-is-not-a-join-peeking-at-the-sql-it-actually-generates-57el)
- Kysely and typed SQL: [Neon: ORMs vs query builders](https://neon.com/blog/orms-vs-query-builders-for-your-typescript-application),
  [PropelAuth: libraries for writing raw SQL safely](https://www.propelauth.com/post/libraries-for-writing-raw-sql-safely)
- SQLAlchemy: [What's new in 2.1](https://docs.sqlalchemy.org/en/21/changelog/migration_21.html),
  [2.1.0b3 announcement](https://www.sqlalchemy.org/blog/2026/06/27/sqlalchemy-2.1.0b3-released/)
- Hibernate: [Hibernate ORM 7](https://in.relation.to/2025/05/20/hibernate-orm-seven/),
  [Hibernate Data Repositories](https://hibernate.org/repositories)
- EF Core 10: [named query filters](https://www.milanjovanovic.tech/blog/named-query-filters-in-ef-10),
  [Npgsql EF 10 release notes](https://www.npgsql.org/efcore/release-notes/10.0.html)
- SeaORM: [SeaORM 2.0 announcement](https://www.sea-ql.org/blog/2026-07-27-sea-orm-2.0/),
  [nested select post](https://www.sea-ql.org/blog/2025-09-16-sea-orm-2.0)
- Go: [GORM, sqlc, Ent or Bun](https://glukhov.org/post/2025/03/which-orm-to-use-in-go),
  [Bun](https://bun.uptrace.dev/)
- Exposed: [Exposed 1.0 is now available](https://blog.jetbrains.com/kotlin/2026/01/exposed-1-0-is-now-available/),
  [migration guide](https://www.jetbrains.com/help/exposed/migration-guide-1-0-0.html)
- Ecto: [preload conventions](https://elixirforum.com/t/ecto-preload-conventions/28548),
  [preload :force discussion](https://elixirforum.com/t/why-repo-preload-has-the-force-option/18506)
- Doctrine: [ORM 3.4.0 released](https://www.doctrine-project.org/2025/06/28/orm-3.4.0-released.html)
- Laravel: [Automatic relation loading in 12.8](https://laravel-news.com/laravel-12-8-0)
- Tempest: [Tempest 3.0](https://www.phparch.com/2026/07/tempest-3-0-the-php-framework-that-gets-out-of-your-way/),
  [tempest/database](https://packagist.org/packages/tempest/database)
- PHP: [PDO driver specific subclasses RFC](https://wiki.php.net/rfc/pdo_driver_specific_subclasses),
  [PDO::connect](https://www.php.net/manual/en/pdo.connect.php)
- Atlas: [declarative migrations](https://atlasgo.io/declarative/apply)
- N+1: [Rails strict loading (thoughtbot)](https://thoughtbot.com/blog/strict-loading-in-rails-8-a-railsy-way-to-avoid-n-1-queries),
  [Laravel strict mode](https://amitmerchant.com/eloquent-strict-mode-in-laravel),
  [SQLAlchemy relationship loading](https://dokk.org/documentation/sqlalchemy/rel_1_2_19/orm/loading_relationships)
- Caching: [Drizzle cache](https://orm.drizzle.team/docs/cache),
  [Upstash Drizzle integration](https://upstash.com/docs/redis/integrations/drizzle),
  [Prisma Accelerate API](https://www.prisma.io/docs/accelerate/reference/api-reference),
  [Doctrine second-level cache](https://www.doctrine-project.org/projects/doctrine-orm/en/3.7/reference/second-level-cache.html),
  [Hibernate caching (Vlad Mihalcea)](https://vladmihalcea.com/high-performance-java-persistence-chapter-16-caching/),
  [Hibernate query cache pitfalls](https://dzone.com/articles/pitfalls-hibernate-second-0)
