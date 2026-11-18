# Command line

`shirakaba/console` turns typed callables into commands ([background work](../design/10-background-work.md)), the way the core turns
them into HTTP handlers:

```php
$cli = new ConsoleBuilder('app', 'Commands of my application.');
$cli->command('users:import', static function (
    Output $output,
    #[Argument('CSV file to read')] string $file,
    #[Option('Report what would change')] bool $dryRun,
    #[Option('Rows per transaction')] int $batch = 500,
) use ($database): int {
    // ...
    $output->line('Imported 42 users.');

    return 0;
}, 'Imports users from a CSV file.');

exit($cli->build()->run($argv));
```

- Arguments are positional in declaration order; a default makes one optional, and a
  variadic parameter (`string ...$files`) takes the rest.
- Options are the parameter in kebab-case: `--dry-run`, `--batch=1000` or `--batch 1000`.
  A `bool` option is a flag with a `--no-` form; an `array` option collects repeated values;
  `#[Option(short: 'b')]` adds `-b`.
- Values are parsed strictly like request parameters: `int`, `float`, `bool`, backed enums,
  `DateTimeImmutable` (RFC 3339 with offset) and `LocalDate`.
- Help comes from the same declarations: `app help`, `app help users:import`,
  `app users:import --help`.
- `build()` fails on a parameter that cannot come from the command line, before any command
  runs.

## Exit codes

| Code | Meaning |
|---:|---|
| 0 | Success, or what the command returns |
| 1 | An uncaught exception: its class and message on standard error, the trace with `--verbose` |
| 2 | A usage error: unknown command or option, missing or invalid value, or a `UsageError` the command throws |
| 130 | Interrupted: the command stopped with `RequestCancelled` |

## Long-running commands

A command that takes a `Cancellation` sees it requested on SIGINT and SIGTERM (with
`ext-pcntl`), so it can stop between units of work instead of being killed in the middle:

```php
$cli->command('reindex', static function (Cancellation $cancellation) use ($index): int {
    foreach ($index->batches() as $batch) {
        if ($cancellation->isRequested()) {
            return 0;
        }
        $index->rebuild($batch);
    }

    return 0;
});
```

## Framework commands in your console

Migrations and the OpenAPI document can live in the same binary as your commands:

```php
MigrationCommands::register($cli, static fn (): Migrations => new Migrations($database, $migrations, $entities), prefix: 'db:');
OpenApiCommand::register($cli, static fn (): AppBuilder => App::builder($database), static fn (): Generator => $generator);
```

`MigrationCommands` and `EntityCommands` come with `shirakaba/database-bridge`.
`examples/tasks/bin/tasks` shows a complete console. `shirakaba-migrate` and `shirakaba-openapi`
are built the same way.

## Inspecting the application

`RouteCommands` and `EntityCommands` answer what the code declares, for people and coding
agents alike:

```php
RouteCommands::register($cli, static fn (): AppBuilder => App::builder($database));
EntityCommands::register($cli, static fn (): array => [Task::class, Project::class], static fn () => $database->connect()->reader());
```

| Command | Shows |
| --- | --- |
| `routes` | method, path, name and handler of every route |
| `routes:show tasks.update` or `routes:show "PATCH /tasks/{id}"` | the handler, each parameter (source, type, required, default, constraints) and the declared responses |
| `entities` | entity classes and their tables |
| `entities:show Task` (class, short name or table) | columns, relations, scopes and soft delete |
| `entities:check` | differences between the mappings and the database (exit 1 when there are any) |

`--json` prints a stable shape instead of text:

- `routes`: a list of `{method, path, name, handler, documented}`.
- `routes:show`: those fields plus `parameters` (`{source, name, parameter, type, required,
  default?, constraints?, mediaTypes?}`), `undescribedParameters`, `responses`
  (`{status, contentType, type, description}`) and `returns` (declared return classes).
- `entities`: a list of `{class, table}`.
- `entities:show`: `{class, table, columns, relations, defaultScopes, requiredScopes,
  softDelete}`; a column is `{property, column, type, kind, nullable, id, generated, managed,
  default?}` and a relation `{name, kind, entity, foreignKey, through?, localKey?, orderBy?}`.
- A `type` is `{kind, nullable, ...}`: `kind` is `string`, `int`, `float` or `bool` (with
  `format`), `enum` (`class`, `values`), `list` (`items`), `map` (`values`), `object` (`class`,
  `name`, `properties`, `additionalProperties`), `union` (`class`, `name`, `discriminator`,
  `members`), `const` (`value`), `file` (`mediaTypes`), `events` (`events`) or `mixed`.

## Testing

```php
$result = new ConsoleTester($cli->build())->run('users:import', 'users.csv', '--dry-run');
self::assertSame(0, $result->exitCode);
self::assertStringContainsString('Imported', $result->output);
```
