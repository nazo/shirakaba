# db-parameter: #[Db] needs a Reader, a Writer or a class built from one

`#[Db]` gives a handler the request's database access ([database](../design/08-database.md)). It accepts `Reader`,
`Writer`, or a class whose constructor takes exactly one `Reader` or `Writer` plus, optionally,
services typed with classes or interfaces ([database](../design/08-database.md)). Anything else (`PDO`, `Database`,
`Connection`, a class with two database parameters) fails the build.

## Fixes

**A. Ask for the access you need.** `#[Db] Reader $db` for reads, `#[Db] Writer $db` for
writes. A GET or HEAD handler must not take a Writer; shirakaba/phpstan reports it.

**B. Wrap queries in a class.** A class with one Reader or Writer parameter keeps SQL out of
handlers and documents what the handler may do:

```php
final readonly class TaskQueries
{
    public function __construct(private Reader $db) {}

    public function find(TaskId $id): ?TaskView { /* ... */ }
}

public function show(#[Path] TaskId $id, #[Db] TaskQueries $tasks): Json
```

**C. Add services next to the Reader or Writer.** Other constructor parameters come from the
request scope by type; register them once with `new Provide([ClockInterface::class =>
$clock])`. Trade-off: a missing service fails the request, not the build.

## Common mistakes

- Taking `PDO` or `Database`: the connection belongs to the request and is released with it;
  never hold it yourself.
- Two database parameters in one class: split it into a query class and a command class.
- An untyped or scalar extra parameter (`string $bucket`): wrap configuration in a class and
  provide that.
