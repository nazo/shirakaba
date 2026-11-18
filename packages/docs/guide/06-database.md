# Databases

```sh
composer require shirakaba/database-bridge
```

`shirakaba/database-bridge` connects the database packages to the framework and installs
them: `shirakaba/database` (connections), `shirakaba/query` (the query builder),
`shirakaba/orm` and `shirakaba/migrations`. The packages themselves do not depend on the
framework and work on their own; see [Without the framework](#without-the-framework).

PHP needs the PDO driver for your database: `pdo_mysql`, `pdo_pgsql` (built against
libpq) or `pdo_sqlite`. Check with `php -m | grep -i pdo`.

```php
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;

$database = new Database(static fn (): PDO => PDO::connect($dsn, $user, $password));
$app->middleware(new DatabaseMiddleware($database));
```

Connections are leased on first use and released with the request scope; a transaction left
open is rolled back ([runtimes and scopes](../design/03-runtimes-and-scopes.md)). `DatabaseMiddleware` also answers the ORM's errors:
`EntityNotFound` becomes a 404 and `StaleEntity` a 409.

## Queries and commands

Data access classes take a `Reader` or a `Writer` in their constructor, and handlers ask for
them with `#[Db]`; they are built for each request ([database](../design/08-database.md)):

```php
final readonly class TaskQueries
{
    public function __construct(private Reader $db) {}

    public function find(int $id): ?Task { ... $this->db->fetchOne(...) ... }
}

final readonly class TaskCommands
{
    public function __construct(private Writer $db) {}

    public function add(string $title): Task { ... $this->db->execute(...) ... }
}

public function show(#[Path] TaskId $id, #[Db] TaskQueries $tasks): Json
public function create(#[JsonBody] NewTask $input, #[Db] TaskCommands $tasks): Json
```

`Writer` can read too, and offers `transaction(fn (Writer $db) => ...)`. A transaction
inside another runs in a savepoint, so an inner failure undoes only the inner work.
`transaction($work, retries: 2)` reruns an outermost transaction that hit a deadlock or
serialisation failure; `$work` must then be safe to repeat.

The constructor's other parameters take application services, such as a clock, a storage
client or a `Publisher`, by type from the request scope. Put them there once with the
`Provide` middleware ([database](../design/08-database.md)); a parameter with a default may be left unprovided:

```php
final readonly class NoteCommands
{
    public function __construct(private Writer $db, private ClockInterface $clock, private Publisher $publisher) {}
}

$app->middleware(new Provide([ClockInterface::class => $clock, Publisher::class => $pubsub]));
```

The split documents which handlers write, and `shirakaba/phpstan` enforces it: a GET or HEAD
handler that takes a `Writer`, or a class built from one, is reported ([static analysis and agents](../design/13-static-analysis-and-agents.md)).

### Several databases and replicas

Name further databases in the middleware and select them with `#[Db('name')]`; each database
leases its own connection per request ([database](../design/08-database.md)):

```php
$app->middleware(new DatabaseMiddleware($main, ['analytics' => $analytics]));

public function report(#[Db] OrderQueries $orders, #[Db('analytics')] Reader $events): Json
```

Give a database a read replica, and readers of GET and HEAD requests use it; other requests,
and every Writer, use the primary. A request that has taken a Writer reads from the primary
from then on, so it sees its own writes:

```php
$main = new Database($connectPrimary, reuse: true, replica: new Database($connectReplica, reuse: true));

public function show(#[Path] int $id, #[Db] TaskQueries $tasks): Json                    // replica
public function balance(#[Db(primary: true)] AccountQueries $accounts): Json            // primary
```

Replicas lag behind the primary, so a GET right after a POST from the same client may not
see the POST's data yet; read with `#[Db(primary: true)]` where that matters. Outside handlers,
`RequestDatabase::replicaReader($database, $scope)` makes the same choice and
`RequestDatabase::reader($database, $scope)` always uses the primary.

## Dialects and errors

MySQL, PostgreSQL and SQLite are supported; the dialect is detected from the driver and
configures each connection: exceptions, native prepared statements, UTC session time
zone, strict SQL mode and `utf8mb4` on MySQL, foreign keys on SQLite. `$db->dialect()`
quotes identifiers and builds `LIMIT` and upsert clauses.

Parameters may be scalars, `null`, backed enums, `DateTimeImmutable` (written in UTC) and
`LocalDate` ([date and time](../design/07-date-and-time.md)). Driver errors become typed exceptions, the same on every database:

```php
try {
    $db->execute('INSERT INTO users (email) VALUES (?)', [$email]);
} catch (UniqueViolation) {
    throw HttpFailure::conflict('The e-mail address is already registered.');
}
```

`UniqueViolation`, `ForeignKeyViolation`, `NotNullViolation`, `TransientFailure`,
`QueryTimedOut` and `ConnectionLost` extend `DatabaseError`.

`$db->withTimeout($seconds)` returns the same Reader or Writer with a time limit for each
statement: PostgreSQL cancels any statement (`statement_timeout`), MySQL a SELECT
(`max_execution_time`), and both raise `QueryTimedOut`. The connection sets the limit only
when it changes, so statements with the same limit share one `SET`, and the next statement
without a limit resets it; a limit never applies to a statement that did not ask for it, and
never outlives the request on a reused connection. SQLite has no such limit and runs the
statement to completion. Bound searches that may scan a whole table:

```php
try {
    $notes = Entities::query(Note::class)->whereColumns(...)->limit(20)->fetch($db->withTimeout(0.5));
} catch (QueryTimedOut) {
    throw new HttpFailure(503, 'The search took too long; try a longer search term.');
}
```

Code that holds the request scope but is not a handler (an authenticator, a job handler)
uses the same per-request connection through `RequestDatabase`:
`RequestDatabase::writer($database, $scope)`. Outside a request (scripts, migrations, tests),
`$database->connect()` opens a connection the caller owns: `$database->connect()->writer()`.

Instants are stored in UTC: use `timestamptz(6)` on PostgreSQL, `DATETIME(6)` on MySQL and
text on SQLite, which Shirakaba writes as `2026-11-11T00:30:00.000000Z` so it sorts in time
order. Columns that already hold local times are not converted automatically; read them
with a `ColumnType` that names their timezone.

MySQL ignores `REFERENCES` written on a column; declare foreign keys as table constraints
(`FOREIGN KEY (parent_id) REFERENCES parents (id)`).

## Query builder

`shirakaba/query` builds SQL from immutable values. Reads run with a `Reader`, writes need a
`Writer`, so the safe-method rule still applies:

```php
$tasks = Select::from('tasks')
    ->columns('id', 'title', 'done', 'created_at')
    ->where('done', '=', false)
    ->whereGroup(fn (Conditions $c) => $c->where('priority', '>', 3)->orWhereNull('due_on'))
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->fetchInto(TaskSummary::class, $reader);   // list<TaskSummary>

$id = Insert::into('tasks')->values(['title' => $title, 'done' => false])->executeAndGetId($writer);
Update::table('tasks')->set(['done' => true])->where('id', '=', $id)->execute($writer);
Delete::from('tasks')->where('id', '=', $id)->execute($writer);
```

- Names are plain identifiers (`column`, `table.column`); anything else is rejected, so a
  sort column taken from a request cannot inject SQL. Expressions use
  `Sql::raw('LOWER(email) = ?', [$email])`; every value is a bound parameter.
- `whereIn('id', [])` is valid and matches nothing.
- `UPDATE` and `DELETE` without `WHERE` throw unless you call `everyRow()`.
- `fetchInto()` maps rows through the constructor: columns match parameter names or their
  snake_case form, and values are converted to `int`, `float`, `bool`, enums,
  `DateTimeImmutable` (UTC) and `LocalDate`.
- `onConflict(['email'], ['name'])` writes an upsert for each database, and
  `onConflict(['note_id', 'reaction'], ['count' => Sql::raw('reaction_counts.count + 1')])`
  sets a column to an expression, where `<table>.<column>` is the stored row and
  `excluded.<column>` the inserted one; `returning()`
  works on PostgreSQL and SQLite, and `executeAndGetId()` everywhere.
- `after(['id' => $lastId])` pages by key instead of `OFFSET`; `iterate()` streams rows;
  `forUpdate()` locks rows (left out on SQLite, which has no row locks).
- `toSql($dialect)` returns the SQL and parameters for tests and debugging.

## Entities

`shirakaba/orm` maps plain readonly classes to tables ([database](../design/08-database.md)). There is no unit of work:
reads return immutable entities, and each write is one statement you ask for.

```php
#[Table('posts')]
#[BelongsTo('author', Author::class, 'authorId')]
#[HasMany('comments', Comment::class, 'postId', orderBy: 'id')]
#[ManyToMany('files', DriveFile::class, through: 'note_files', localKey: 'note_id', foreignKey: 'file_id', orderBy: 'position')]
final readonly class Post
{
    public function __construct(
        public AuthorId $authorId,                  // single-value classes map to their value
        public string $title,
        public PostStatus $status = PostStatus::Draft,
        public ?LocalDate $publishedOn = null,
        #[Json] public array $meta = [],
        #[CreatedAt] public ?DateTimeImmutable $createdAt = null,
        #[Version] public int $version = 0,
        #[Id, Generated] public ?int $id = null,
    ) {}
}

$post = Entities::insert(new Post($authorId, 'Hello'), $writer);      // id, createdAt, version filled
$post = Entities::get(Post::class, $id, $reader);                      // 404 when missing
$posts = Entities::query(Post::class)->where('status', '=', PostStatus::Published)->orderBy('id')->fetch($reader);
$post = Entities::update(Entities::change($post, ['title' => 'Hi']), $writer); // 409 if stale
Entities::patch(Post::class, $id, ['status' => PostStatus::Draft], $writer);   // named columns only
Entities::delete($post, $writer);
```

Generated keys, timestamps and versions are nullable or defaulted, because a new entity
has none yet. PHP requires parameters with defaults to come after those without, so they
sit at the end of the constructor, and `new Post($authorId, 'Hello')` leaves them out.
Named arguments (`new Post(title: 'Hello', authorId: $authorId)`) read well when the
order matters less than the names.

`#[Version]` starts at 1 on insert, whatever the property's default, and grows with every
`update()`, `patch()` and bulk update. `#[Table('todos', label: 'Todo')]` names the entity
in API errors ("The Todo was not found."); without a label they say "resource" and never
show the class name.

Bulk changes go through a query and keep the managed columns right: versions increase,
`#[UpdatedAt]` is set, scopes and soft deletes apply.

```php
Entities::query(Todo::class)->where('completed', '=', false)->update(['completed' => true], $writer);
Entities::query(Todo::class)->where('completed', '=', true)->delete($writer);
```

Return entities from APIs through a response DTO or a projection, not as they are.
Generated keys and timestamps are nullable in the entity so new ones can omit them, which
is the wrong type for a response, and renaming an entity class would rename the API type.
Response schemas (`#[JsonResponse]`) list every field as required, because every field is
written; `#[JsonDto(name: 'Todo')]` fixes the public schema name independently of the class.

A response DTO needs no mapping code. Its constructor parameters take the entity
properties of the same names, so it declares `int $id` where the entity has `?int $id`,
and a null (an entity that was never inserted) fails instead of reaching the client.
`into()` fills it from a query, `Entities::into()` from an entity in hand, such as the
result of `insert()` or `update()`:

```php
#[JsonDto(name: 'Todo')]
final readonly class TodoView
{
    public function __construct(
        public int $id,
        public string $title,
        public bool $completed,
        public DateTimeImmutable $createdAt,
    ) {}
}

$views = Entities::query(Todo::class)->orderBy('id')->into(TodoView::class, $reader);
$view = Entities::into(Entities::insert(new Todo('Write docs'), $writer), TodoView::class);
return new Json($view, headers: ['Location' => '/todos/' . $view->id]);   // $view->id is an int
```

Each parameter must name an entity property (with the same or a wider type), a requested
relation or the entity itself, so a typo fails before any query runs, and the
`shirakaba/phpstan` rule reports it during analysis. `examples/tasks` shows entities, response
DTOs, optimistic locking and a bulk update in a small API.

Columns are the snake_case property names unless `#[Column('name')]` says otherwise;
`#[Embedded]` spreads a value object over prefixed columns; `#[Column(type: ...)]` takes a
`ColumnType` for anything else.

Relations are declared on the class but never loaded on access. Ask for them and read the
result into a projection, a class with the entity (or its properties by name) and one
parameter per relation:

```php
final readonly class PostView
{
    /** @param list<CommentView> $comments */
    public function __construct(
        public Post $post,
        public Author $author,
        #[Items(CommentView::class)] public array $comments,   // CommentView(Comment $comment, Author $author)
    ) {}
}

$views = Entities::query(Post::class)->with('author', 'comments.author')->into(PostView::class, $reader);
```

`withWhere('comments', fn (EntityQuery $c) => $c->where('approved', '=', true))` filters or
orders the loaded rows; `withCount('comments')` fills an `int $commentsCount` parameter
with one grouped query. `#[Json]` also stores a DTO, mapped with `shirakaba/json`'s rules.

A response DTO can be the projection itself. `#[From('notifier')] UserView $user` takes a
property, relation or count of another name, and `#[Computed]` parameters take values the
entity does not have, such as counts from a separate query. The callback receives all
loaded entities, so one query serves the page, and returns the values by index:

```php
$views = Entities::query(Note::class)->with('user')->into(NoteView::class, $reader,
    static function (array $notes) use ($reactionCounts, $reader): array {
        $reactions = $reactionCounts->forNotes(array_map(static fn (Note $n): int => $n->id, $notes), $reader);

        return array_map(static fn (Note $n): array => ['reactions' => $reactions[$n->id] ?? []], $notes);
    });
```

`Entities::into($note, NoteView::class, ['reactions' => $counts])` does the same for one
entity. A computed parameter with a default may be left out.

Nested projections can have `#[Computed]` parameters too, such as the reply inside a note
view. The callback then receives those related entities as a second list; return their values
after the query's, so one query still serves every level:

```php
static function (array $notes, array $related) use ($reactionCounts, $reader): array {
    $all = [...$notes, ...$related];
    $reactions = $reactionCounts->forNotes(array_map(static fn (Note $n): int => $n->id, $all), $reader);

    return array_map(static fn (Note $n): array => ['reactions' => $reactions[$n->id] ?? []], $all);
}
```

Each relation level is one `WHERE … IN (…)` query, and belongs-to and many-to-many relations
at the end of a path share their queries across paths (one join-table read per relation, one
read per entity class), so this runs three statements (posts, comments,
authors) however many posts there are. A relation filtered with `withWhere()` keeps its own
query. A projection whose parameters do not match the requested relations
is rejected before any query runs. `whereHas('comments', fn ($c) => $c->where('approved',
'=', true))` filters by relations with `EXISTS`.

Conditions on an entity's properties are joined with AND. For OR, group them; names and values
are mapped as in `where()`, groups nest, and `whereNotIn()` excludes values or a subquery's results:

```php
// Own notes, or public notes of the people I follow.
Entities::query(Note::class)
    ->whereGroup(fn (PropertyConditions $c) => $c
        ->where('userId', '=', $me)
        ->orWhereGroup(fn (PropertyConditions $g) => $g
            ->whereIn('userId', $followed)
            ->where('visibility', '=', Visibility::Public)))
    ->fetch($db);
```

### Scopes and soft deletes

Filters that must apply to every query of an entity are scopes. A `#[RequiredScope]`
must be passed by each query, so a forgotten tenant fails instead of leaking rows; a
`#[DefaultScope]` always applies:

```php
#[Table('projects')]
#[RequiredScope(InTenant::class)]
#[SoftDelete]                                   // delete() sets deletedAt; queries skip such rows
final readonly class Project { /* ... public ?DateTimeImmutable $deletedAt = null ... */ }

Entities::query(Project::class)->scoped(new InTenant($tenantId))->fetch($reader);
Entities::get(Project::class, $id, $reader, [new InTenant($tenantId)]);
Entities::query(Project::class)->scoped($tenant)->withoutScope(NotDeleted::class)->fetch($reader); // include deleted
```

Scopes also apply to related entities loaded with `with()`, to `whereHas()`, and to writes
by key: `Entities::update($project, $writer, scopes: [$tenant])` (likewise `patch()`,
`delete()`, `restore()`, `forceDelete()`) touches the row only inside the scope, so a forged
key cannot reach another tenant's row. `upsert()` refuses entities with required scopes. They are
switched off one by one with `withoutScope()`; there is no way to switch off all of them.
`Entities::restore()` and `Entities::forceDelete()` undo or complete a soft delete.

### Multi-tenant applications

Give every tenant-owned entity a required scope, and let the scope also check inserts, so a
forged tenant id is refused:

```php
final readonly class InOrganization implements Scope, ScopeOnInsert
{
    public function __construct(private string $organizationId) {}

    public function apply(EntityQuery $query): EntityQuery
    {
        return $query->where('organizationId', '=', $this->organizationId);
    }

    public function admits(object $entity): bool
    {
        return $entity->organizationId === $this->organizationId;
    }
}

#[Table('boards')]
#[RequiredScope(InOrganization::class)]
final readonly class Board { ... public string $organizationId ... }

Entities::insert($board, $writer, scopes: [$org]);              // ScopeViolation for another organization
Entities::load($boards, 'cards', $reader, [$org]);              // relations take the scope too
```

A table without its own tenant column is scoped through its parent: the scope's `apply()`
calls `whereHas('board', ...)`. Data access classes that take the organization once keep the
handlers short; put the scope in them with `Provide` ([database](../design/08-database.md)).

`shirakaba/phpstan` reports a query of a scoped entity that cannot have the scope: a chain from
`Entities::query()` that is executed without `scoped()` or `withoutScope()`, and
`Entities::find()`, `get()`, `update()`, `patch()`, `delete()`, `restore()`, `forceDelete()`,
`insert()`, `insertAll()` or `load()` without it in `$scopes`. Queries built across functions
are left to `ScopeMissing` at run time, so keep a test that reads as another tenant.

On PostgreSQL, row-level security adds a second line of defence that also covers raw queries
([database](../design/08-database.md)). Set the tenant on the request's connection right after authentication:

```sql
ALTER TABLE boards ENABLE ROW LEVEL SECURITY;
CREATE POLICY boards_tenant ON boards
    USING (organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid);
```

```php
final readonly class TenantSetting implements MiddlewareInterface
{
    public function __construct(private Database $database) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = RequestScope::of($request);
        $user = $scope->find(User::class);
        if ($user !== null) {
            RequestDatabase::setting($this->database, $scope, 'app.organization_id', $user->organizationId);
        }

        return $handler->handle($request);
    }
}
```

The setting applies to every statement of the request, is set again after a rollback, and is
cleared before the connection serves another request. RLS binds only roles that do not own
the table (or tables with `FORCE ROW LEVEL SECURITY`) and are not superusers, so connect as
an application role; a pooler in transaction mode (PgBouncer) breaks session settings.
MySQL and SQLite have no RLS, and `setting()` refuses there.

## Caching query results

Nothing is cached unless you ask. `shirakaba/query-cache` caches chosen reads in any PSR-16
cache, tagged with the tables they read:

```php
$cache = new QueryCache($psr16, 'shop');   // application-scoped; the namespace names the database

$countries = $cache->fetchInto(Country::class, Select::from('countries')->orderBy('name'), $reader, ttl: 3600);
$cache->execute(Update::table('countries')->set(['name' => $name])->where('code', '=', $code), $writer);
$cache->invalidate($writer, 'pricing');   // a custom tag
```

Writes through `execute()` and `invalidate()` retire tags after the transaction commits;
a rolled-back write retires nothing (`Writer::afterCommit()` is available for your own
side effects). Entries are JSON, never `serialize()`, and hits and misses appear in the
access log and traces as `db.cache.hit` and `db.cache.miss`.

## Migrations

`shirakaba/migrations` applies versioned schema changes written in PHP. List them in order;
the keys are permanent IDs:

```php
final readonly class CreateTasks implements Reversible
{
    public function up(Schema $schema): void
    {
        $schema->create('tasks', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('owner_id');
            $table->string('title', 200);
            $table->boolean('done')->default(false);
            $table->instant('created_at');
            $table->date('due_on')->nullable();
            $table->string('code', 16)->bytewise();     // binary collation: case-sensitive, byte order
            $table->foreign('owner_id')->references('users')->onDelete('cascade');
            $table->index(['owner_id', 'done']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('tasks');
    }
}

// migrations.php, for the CLI
return new Migrations($database, ['2026_11_11_create_tasks' => new CreateTasks()]);
```

```sh
vendor/bin/shirakaba-migrate status --config=migrations.php
vendor/bin/shirakaba-migrate migrate --config=migrations.php --dry-run   # print the SQL
vendor/bin/shirakaba-migrate migrate --config=migrations.php
vendor/bin/shirakaba-migrate rollback --config=migrations.php --steps=1
```

- Column types follow [date and time](../design/07-date-and-time.md): `instant()` stores UTC with microseconds, `date()` a
  `LocalDate`, `json()` is `JSONB` on PostgreSQL.
- Foreign keys are table constraints, which MySQL requires. SQLite cannot add one to an
  existing table.
- On PostgreSQL and SQLite each migration runs in a transaction. MySQL commits DDL
  implicitly, so a migration that fails halfway there needs repair by hand; keep MySQL
  migrations to one change each.
- An advisory lock on MySQL and PostgreSQL keeps two deployments from migrating at once.

Indexes are built with `index()`, `unique()` and `expressionIndex()`, and take an index
method, a PostgreSQL operator class and, for an expression, a statistics target. A substring
search such as `LIKE '%term%'` needs a trigram index on PostgreSQL:

```php
public function up(Schema $schema): void
{
    $schema->extension('pg_trgm');
    $schema->alter('notes', static function (Blueprint $table): void {
        $table->expressionIndex('notes_search', "lower(coalesce(cw, '') || ' ' || coalesce(text, ''))")
            ->using('gin')->operatorClass('gin_trgm_ops')->statistics(1000);
    });
}
```

Queries must use the same expression to use the index (`whereColumns()` with `Sql::raw()`).
The statistics target matters for text: at the default of 100, PostgreSQL may take a rare word
for a common one and scan the table in key order instead. The expression is SQL as written for
the database at hand (MySQL concatenates with `CONCAT`, not `||`), and what a database cannot
express (GIN on MySQL, operator classes outside PostgreSQL) fails when the migration is built.
Searches whose pattern is too short for trigrams still scan; bound them with `withTimeout()`.

After migrating, `SchemaCheck::entities([Task::class, ...], $reader)` lists entity
properties whose columns are missing or nullable when the property is not, for a CI step.

```sh
vendor/bin/shirakaba-migrate lint --config=migrations.php            # risky changes in pending migrations
vendor/bin/shirakaba-migrate diff --config=migrations.php --class=AddDueDates --namespace='App\Migrations'
vendor/bin/shirakaba-migrate diff --config=migrations.php --check   # exits with 1 when a migration is missing
```

`lint` warns about dropped tables and columns, renames, NOT NULL columns without a
default, and indexes or foreign keys added to existing tables, and exits with 1, so CI can
stop a deployment. `diff` prints a migration for the tables, join tables, foreign keys and
columns the entities listed in `new Migrations($db, [...], entities: [...])` need. It
never drops or changes anything, so review it and add indexes before committing it.

## Without the framework

`shirakaba/database`, `shirakaba/query`, `shirakaba/query-cache`, `shirakaba/migrations` and
`shirakaba/orm` require no framework package ([database](../design/08-database.md)), so a script, a worker or an
application on another framework can use them alone:

```sh
composer require shirakaba/orm
```

```php
$database = new Database(static fn (): PDO => PDO::connect($dsn, $user, $password), reuse: true);

$lease = $database->lease();                    // borrow a pooled connection
try {
    $notes = Entities::query(Note::class)->where('done', '=', false)->fetch($lease->connection->reader());
} finally {
    $lease->release();                          // roll back what is open, reset, return to the pool
}
```

`lease()` takes an optional `EventSink`, which receives a `ConnectionLeased` and then a
`StatementExecuted` for every statement (`DatabaseEvent`s with a name, a duration and
attributes); the bridge passes one that reports them to the request's observers.
`#[Json]` columns holding arrays need nothing more; columns holding a DTO need
`shirakaba/json`. The commands (`EntityCommands`, `MigrationCommands`, `shirakaba-migrate`)
and the 404/409 answers for entity errors come with the bridge.
