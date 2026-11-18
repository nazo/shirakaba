# Routing and handlers

## Routes

```php
$app->get('/users', $handlers->list(...));
$app->post('/users', $handlers->create(...));
$app->get('/users/{id}', $handlers->show(...))->name('users.show');
$app->route(['PUT', 'PATCH'], '/users/{id}', $handlers->update(...));
$app->get('/files/{path...}', $files->download(...)); // catch-all, last segment only
```

Groups share a prefix and middleware; `''` is the group's own path:

```php
$app->group('/admin', function (RouteGroup $admin) use ($auth, $handlers): void {
    $admin->middleware($auth);
    $admin->get('', $handlers->dashboard(...));     // /admin
    $admin->get('/stats', $handlers->stats(...));   // /admin/stats
});
```

Matching ([routing and handlers](../design/04-routing-and-handlers.md)):

- static segments beat parameters, parameters beat catch-alls, decided from the left;
- `/users` and `/users/` are different paths;
- an unknown path is a 404, a known path with the wrong method a 405 with `Allow`;
- `HEAD` falls back to `GET` without a body, and `OPTIONS` is answered automatically;
- two routes with the same method and shape are a build error.

## Handlers

A handler is any callable. The recommended style is a class whose constructor takes the
application's services, registered method by method with first-class callables
([principles](../design/01-principles.md)):

```php
final readonly class UserHandlers
{
    public function __construct(private Mailer $mailer) {}

    public function show(#[Path] UserId $id, #[Db] UserQueries $users): Json { ... }
}

$handlers = new UserHandlers($mailer);
$app->get('/users/{id}', $handlers->show(...));
```

Handlers are built once and, under worker runtimes, serve many requests: never store
request data in their properties ([runtimes and scopes](../design/03-runtimes-and-scopes.md); `shirakaba/phpstan` checks this).

Every parameter needs an extractor attribute (see [Reading request data](03-request-data.md))
or one of the injected types `ServerRequestInterface` and `RequestScope`. The return type
must be `ResponseInterface` or a `ResponseValue` such as `Text`, `NoContent` or `Json`;
arrays and other objects are never converted automatically.

## Middleware

Any PSR-15 middleware works:

```php
$app->middleware(
    new TrustedProxies(['10.0.0.0/8'], [ForwardedHeader::For, ForwardedHeader::Proto]),
    new Cors(['https://app.example.com']),
);
```

Global middleware runs for every request, including 404s and errors, so it can decorate
error responses. Group and route middleware (`->middleware(...)` on a group or route) run
only for matched routes, outer groups first.

## Request scope

`RequestScope` holds what belongs to one request: values registered by type, cleanup
callbacks, cancellation and an optional deadline ([runtimes and scopes](../design/03-runtimes-and-scopes.md)).

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    RequestScope::of($request)->set($this->tenants->resolve($request));

    return $handler->handle($request);
}

public function report(RequestScope $scope): Json
{
    $tenant = $scope->get(Tenant::class);
    $lock = $this->locks->acquire($tenant);
    $scope->defer($lock->release(...)); // runs when the request ends, even after failures
    ...
}
```
