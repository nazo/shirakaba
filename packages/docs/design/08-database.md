# Database

Shirakaba's database support is a stack of small packages over PDO for MySQL, PostgreSQL and
SQLite. Its shape follows where current data libraries have converged (SQL-shaped typed builders,
plain entity classes, explicit loading with no hidden queries, explicit writes, opt-in caching;
see `docs/research/2026-11-orm-and-database-layers.md` in the repository), plus Shirakaba's own
constraints: no state shared between requests in worker runtimes, static analysis instead of a
compile step, configuration in PHP and few dependencies.

## Packages

| Package | Contents |
|---|---|
| `shirakaba/database` | connections, pools, `Reader` and `Writer`, transactions, dialects, typed errors |
| `shirakaba/query` | a typed query builder producing SQL and parameters; knows tables and columns, not entities |
| `shirakaba/orm` | entity mapping, hydration, explicit writes, relations loaded into projections, scopes |
| `shirakaba/migrations` | versioned schema changes in PHP, with diff and lint |
| `shirakaba/query-cache` | an opt-in result cache over PSR-16 |
| `shirakaba/database-bridge` | the integration with the framework: `#[Db]`, `DatabaseMiddleware`, request leases, commands |

Each package is usable without the next, and dependencies point in one direction. The
implementation is Shirakaba's own over PDO (`PDO::connect()` and the PHP 8.4 driver subclasses);
raw SQL through `Reader` and `Writer` stays a supported path.

### Independent of the framework

The database packages depend on no framework package except `shirakaba/time`: not on
`shirakaba/core`, `shirakaba/console` or `shirakaba/http-message`. `composer require
shirakaba/orm` installs database, query and time, and no HTTP code. The monorepo validator refuses
a dependency from them to a framework package.

The integration lives in `shirakaba/database-bridge`, which applications on the framework require:

- `Database::lease()` hands out a connection from the pool that the caller must release
  (`Lease::release()` rolls back, resets and returns it, or closes it); `connect()` opens a
  connection the caller owns. `RequestDatabase::connection()`, `reader()`, `replicaReader()` and
  `writer()` keep the request's leases in the scope and release them with `$scope->defer()`.
- The database packages report events through their own `DatabaseEvent` and `EventSink`; the
  bridge records them on the request scope as core events, so log and tracing observers see
  statements, leases and cache lookups.
- The ORM's `EntityNotFound` and `StaleEntity` are plain exceptions; `DatabaseMiddleware` translates
  them into 404 and 409 ([errors](05-errors-and-observability.md)).
- `#[Json]` columns with DTO types use `shirakaba/json`, which the ORM suggests; without it, mapping
  such a column is a `MappingError` naming the package.
- The bridge also holds `#[Db]`, `StatementLog` and the entity and migration commands.

## Reads, writes and safe methods

Data access classes are built per request from a `Reader` (reads) or a `Writer` (reads and writes):
their constructor takes exactly one of them, and `#[Db]` accepts such classes as well as `Reader`
and `Writer` themselves.

```php
public function show(#[Path] TaskId $id, #[Db] TaskQueries $tasks): Json            // TaskQueries(Reader)
public function create(#[Db] TaskCommands $tasks, #[JsonBody] NewTask $input): Json // TaskCommands(Writer)
```

Reads and writes are separate classes, so the type a handler asks for says whether it writes. A
PHPStan rule reports a GET or HEAD handler that takes a `Writer`, or a class built from one
([static analysis](13-static-analysis-and-agents.md)). No registry, container or "current request"
is needed, and construction is checked when the application is built. Builder and ORM entry points
also come from a `Reader` or a `Writer`, so the rule covers them unchanged.

A `#[Db]` class's constructor may also take other class- or interface-typed parameters, which are
taken from the request scope by type. The `Provide` middleware places application services there:
`new Provide([ClockInterface::class => $clock, ...])`. This is a lookup by type, not a container:
nothing is constructed, and provided services must be safe to share between requests.

## Connections in worker runtimes

A request leases one connection per `Database` object, kept in the scope by object, so any number of
databases can be used together without mixing up their connections.

**Reuse.** `new Database($connect, reuse: true)` keeps the connection when the request ends and gives
it to the next request of the same process, which saves the connection time on every request after
the first. PDO's persistent connections would hand the next request whatever the last one left (an
open transaction, settings, LISTEN registrations, temporary tables, locks), so before a connection is
kept:

- the request's `Connection` is released: open transactions and savepoints are rolled back and
  after-commit callbacks dropped;
- the dialect's `reset()` returns the session to its configured state: `DISCARD ALL` on PostgreSQL
  followed by the configuration; on MySQL, which has no such statement, named locks are released and
  the settings applied again (user variables and temporary tables remain); on SQLite the settings are
  applied again. A connection that cannot be reset is closed;
- a released `Connection` refuses statements, so a Reader or Writer kept past its request fails loudly
  instead of using the next request's session.

A kept connection is not reused when it has been idle longer than `maxIdleSeconds`, open longer than
`maxLifetimeSeconds` or has served `maxUses` requests; after ten seconds idle a `SELECT 1` checks it
first. One idle connection is kept per process, which is all a runtime needs that runs one request at
a time per process. Under Swoole, where a worker serves several requests at once, every concurrent
request still gets a connection of its own, and only one of them is kept afterwards. Each request
reports a `db.connection` event with whether the connection was reused and how long connecting took.
The conformance suite checks that a request leaving a transaction open and changing its session does
not affect the next one.

**Several databases and replicas.** `DatabaseMiddleware($default, ['analytics' => $analytics])` names
further databases, and `#[Db('analytics')]` selects one. `new Database($connect, replica: new
Database($connectReplica))` attaches a read replica:

- `replicaReader()` reads from the replica until the request takes a Writer on the primary, then from
  the primary, so a request always sees its own writes;
- `#[Db]` readers use the replica in GET and HEAD requests and the primary otherwise; writers always use
  the primary. Since safe handlers cannot take a Writer, safe requests are exactly the ones that may
  read slightly old data, and `#[Db(primary: true)]` opts a reader out;
- choosing among several replicas and lag checks belong to the replica's `connect` closure or the
  infrastructure.

**Statement time limits.** `withTimeout($seconds)` returns a Reader or Writer whose statements have a
time limit (`statement_timeout` on PostgreSQL, `max_execution_time` for SELECT on MySQL), raising
`QueryTimedOut`. The connection sets the limit only when it changes and resets it for the next statement
without one, so a limit never applies to a statement that did not ask for it and never outlives the
request.

**Settings for row-level security.** `Connection::setSetting()` records a custom session setting
(`prefix.name`), applied with `set_config(name, value, false)` before the next statement and only when it
changed. A rollback marks applied values unknown, because PostgreSQL undoes a `set_config` made inside
it. The setting lasts as long as the lease; the reset before reuse clears it, so nothing reaches the
next request. `RequestDatabase::setting()` sets it per request from middleware right after
authentication. Only PostgreSQL has row-level security; on MySQL and SQLite `setSetting()` refuses
instead of pretending to protect. It is a defence in depth behind ORM scopes, not a replacement.

## Dialects and errors

The dialect is detected from the driver and configures each connection: exceptions, native prepared
statements, a UTC session time zone, strict SQL mode and `utf8mb4` on MySQL, foreign keys on SQLite.
Driver errors become typed exceptions that are the same on every database (`UniqueViolation`,
`ForeignKeyViolation`, `NotNullViolation`, `TransientFailure`, `QueryTimedOut`, `ConnectionLost`).
Instants are stored in UTC ([date and time](07-date-and-time.md)).

## Query builder

Columns, tables and relations are named by strings, checked by the `shirakaba/phpstan` extension
against the mapping: unknown names, value types that do not match the column, and result shapes.
Entity queries use property names (`createdAt`); the plain builder uses column names. Every value is
a bound parameter; SQL text enters only through an explicit `Sql::raw()`. Generated column constants
or classes were not chosen, because they need a build step and generated files to keep in sync.

## Entities

Entities are plain `final readonly` classes with promoted constructor parameters, mapped by attributes
and hydrated through the constructor: no base class, no proxies, no generated code. Mapping is planned
once and validated when the application is built.

### No unit of work

There is no identity map, dirty checking or flush. Reading a row twice gives two equal objects, and
each write is one statement the caller asks for:

- `update($entity)` writes every mapped column by primary key. Entities are changed with PHP 8.5's
  `clone($task, ['done' => true])`.
- `patch(Task::class, $id, ['done' => true])` writes only the named columns without loading the
  entity, for rows that concurrent requests change in different columns, and for hot paths.
- A `#[Version]` column makes `update()` detect concurrent changes (optimistic locking).

### Relations are loaded into projections

Relations (belongs-to, has-one, has-many, many-to-many) are declared in the entity mapping but are not
properties of the entity. A query that needs related data names the relations with `with()` and maps
the result into a projection: a class whose properties are the root entity (or its properties, by
name) and the requested relations.

```php
#[JsonDto]
final readonly class PostView
{
    /** @param list<Comment> $comments */
    public function __construct(
        public Post $post,
        public Author $author,
        public array $comments,
    ) {}
}

$views = Entities::query(Post::class)->with('author', 'comments')->into(PostView::class, $reader);
```

- An unloaded relation cannot be read: the property does not exist. There is no lazy loading, no
  runtime guard and nothing to configure per environment.
- Each relation level is loaded with one `WHERE key IN (...)` query, chunked for large key sets, so the
  number of statements depends on the number of levels, not rows.
- Projections may nest, may be the JSON response DTOs, and are checked when the query is planned: every
  requested relation needs a matching property and every property must be filled.
- `load($entities, 'relation')` loads relations for entities already in hand; it is batched and explicit
  too.

### Scopes

Filters that must apply to every query of an entity are scopes. A `#[RequiredScope]` must be passed by
each query, so a forgotten tenant fails (`ScopeMissing`) instead of leaking rows; a `#[DefaultScope]`
(such as soft deletes) always applies and is switched off one at a time with `withoutScope()`. Scopes
also apply to related entities, `whereHas()` and writes by key, so a forged key cannot reach another
tenant's row, and a scope can check inserts. The PHPStan extension reports queries of a scoped entity
that cannot have the scope.

### Alternatives not taken

- **Unit of work with an identity map**: per-request state that must be reset reliably in worker
  runtimes, hidden flush-time writes, and proxies or lazy objects for relations. Readonly entities do not
  need one object per row.
- **Active record**: entities that carry a connection and query themselves, global state and mutable
  models.
- **Lazy loading with a guard**: the guard is a runtime check that is usually off in production, where
  N+1 queries hurt.
- **Automatic eager loading**: removes N+1 queries by hiding when and what is queried.
- **Relation properties with a load state**: one class would have different contents depending on the
  query, which is fragile to track statically. Projections give each shape its own type.

Lost updates are possible with `update()` on rows changed concurrently; `patch()` and `#[Version]` are
the answers.

## Caching

Nothing is cached by default. `shirakaba/query-cache` caches chosen reads in any PSR-16 cache, tagged
with the tables they read. Writes through the cache retire tags after the transaction commits, so a
rolled-back write retires nothing. Entries are JSON, never `serialize()`, and hits and misses are
reported as events.

## Migrations

`shirakaba/migrations` applies versioned schema changes written in PHP with a schema builder (indexes
include expression, GIN and trigram indexes on PostgreSQL). `db:diff` compares the entity mapping with
the database, and `db:lint` reports risky changes. The commands are registered in the application's
console through the bridge.
