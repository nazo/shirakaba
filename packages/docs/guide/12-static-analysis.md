# Static analysis

Shirakaba is written for PHPStan at level `max`, and applications benefit from the same.

```sh
composer require --dev phpstan/phpstan shirakaba/phpstan
```

```neon
includes:
    - vendor/shirakaba/phpstan/extension.neon

parameters:
    level: max
    paths: [src, app.php]
    shirakaba:
        longRunningPaths: ['#/src/#']
        clockPaths: ['#/src/#']
        reportQueriesInLoops: true
```

`shirakaba/phpstan` reports:

- static properties, `global` and static variables in `longRunningPaths`, which would
  leak between requests under worker runtimes;
- middleware, handlers, extractors and resolvers that write to their own properties after
  construction;
- GET and HEAD handlers that take a database `Writer`, or a class built from one;
- in `clockPaths`, reads of the wall clock (`time()`, `date()` without a timestamp,
  `new DateTimeImmutable()`) outside a PSR-20 clock. Take a `Psr\Clock\ClockInterface`
  instead: `Shirakaba\Time\SystemClock` in production (always UTC), `FrozenClock` in tests;
- ORM calls checked against entity mappings (with `shirakaba/orm` installed): unknown
  properties in `where()`, `orderBy()`, `after()` and `Entities::patch()`, values of the
  wrong type for a property, unknown relations in `with()` and `whereHas()`, and
  projections passed to `into()` that do not match the relations requested in the same
  chain. Names may be spread from a constant array, as in `with(...self::RELATIONS)`;
  when a name is only known at run time, the projection is not checked;
- with `reportQueriesInLoops`, database calls inside loops, the usual shape of an N+1
  query.

It also types query results: `Select::from('tasks')->columns('id', 'title')->fetchAll($db)`
is `list<array{id: mixed, title: mixed}>` when the query is built in one chain with literal
column names (and `column($expression, 'alias')`), so a misspelt key is reported. Values stay
`mixed`, since the database decides their types; read them with checks or with `fetchInto()`.

Because applications are assembled in plain PHP, PHPStan also checks wiring, option types
and handler signatures that configuration files would hide.
