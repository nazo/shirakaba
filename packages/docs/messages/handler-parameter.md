# handler-parameter: a handler parameter has no source

Shirakaba never guesses where a handler argument comes from ([routing and handlers](../design/04-routing-and-handlers.md)). Every parameter needs an
extractor attribute that says so, or one of the two types the core injects: the PSR-7
`ServerRequestInterface` and the `RequestScope`. The application fails to build otherwise,
before any request is served.

## Fixes

**A. Say where the value comes from.** Use `#[Path]`, `#[Query]`, `#[Header]` or `#[Cookie]`
for single values, `#[JsonBody]` (shirakaba/json) or `#[FormBody]` (shirakaba/form) for the body,
`#[Authenticated]` (shirakaba/auth) for the user, and `#[Db]` (shirakaba/database) for database
access. This is almost always the fix.

**B. Take the service from the handler's own constructor.** Application services (a clock,
a mailer, an HTTP client) are not request data. Give them to the handler object when you wire
the application, or to a `#[Db]` class through the `Provide` middleware ([database](../design/08-database.md)). Trade-off:
the handler must be an object rather than a closure.

**C. Read the request yourself.** Declare `ServerRequestInterface $request` and read it.
Trade-off: the value is untyped, unvalidated and missing from OpenAPI; prefer A.

## Example

```php
final readonly class TaskHandlers
{
    public function __construct(private ClockInterface $clock) {}

    public function update(
        #[Path] TaskId $id,
        #[JsonBody] TaskPatch $input,
        #[Db] TaskCommands $tasks,
        #[Authenticated] User $user,
    ): Json {
        // ...
    }
}

$app->patch('/tasks/{id}', $handlers->update(...))->name('tasks.update');
```

## Common mistakes

- Expecting dependency injection by type, as in Laravel or Symfony: Shirakaba has no container
  ([principles](../design/01-principles.md)).
- Leaving out `#[Path]` because the name matches the `{id}` placeholder: the name is not
  enough.
- Declaring `Request` or a framework-specific request class: use `ServerRequestInterface`.
