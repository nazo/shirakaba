# Routing and handlers

## Declaring routes

Routes are declared by method calls on the application or a route group, as in Express, Hono or
Go's `ServeMux`: `$app->get('/users/{id}', show_user(...))`. Handlers are any callable;
first-class callable syntax is the recommended style. Attribute discovery would require scanning
files, which configuration as code rules out ([principles](01-principles.md)). Attribute-based
routing may come as a separate package that turns attributes into the same method calls, with the
controller classes listed in code rather than found by scanning.

The route table is built once by `build()`; matching uses no reflection at request time.

## Matching

Routing rules decide security-relevant behaviour (percent-decoding, slashes), so they are fixed
and explainable.

**Syntax.** A path template starts with `/` and consists of segments separated by `/`. A segment
is static text, a parameter `{name}`, or, as the last segment only, a catch-all `{name...}`. Names
are PHP identifiers, unique within a template. Static text may not contain `{` or `}`. There are no
regular-expression constraints; typed extraction validates values.

**Request path.** The router uses `UriInterface::getPath()`, which collapses leading slashes
([HTTP messages](02-http-messages.md)). An empty path is `/`. The path is split on `/` first, then
each segment is percent-decoded exactly once, so `%2F` never creates a segment boundary. A decoded
NUL anywhere in the path is a 400.

**Segments.**

- A static segment matches a decoded segment with the same bytes.
- A parameter matches one non-empty segment whose decoded value contains no `/`.
- A catch-all matches all remaining segments (possibly an empty one) and yields them joined with
  `/`; a decoded `/` inside one of them makes it fail.
- Trailing slashes are significant: `/users` and `/users/` are different paths.
- When several templates match, segments are compared from left to right and the first difference
  decides: static beats parameter, parameter beats catch-all. This is simpler than a "most
  specific pattern" rule, but `/a/{x}` silently shadows `/{y}/b` for `/a/b`.
- Two routes with the same method and the same shape (same static texts and parameter positions,
  whatever the names) are a build error.

**Methods.** Methods are case-sensitive; a route registers one or more.

- No matching path: 404.
- A matching path without a route for the method: 405 with `Allow` listing the methods (sorted,
  including `HEAD` when `GET` exists, and always `OPTIONS`).
- `HEAD` uses an explicit HEAD route if there is one, otherwise the GET route with the body
  replaced by an empty one; headers are kept.
- `OPTIONS` uses an explicit route if there is one, otherwise the router answers 204 with `Allow`.
  CORS is the concern of `shirakaba/cors`.

**Middleware order.** Global middleware wraps the router, so it also runs for 404, 405 and
automatic OPTIONS responses. Group middleware (outer groups first) and then route middleware wrap
the handler of a matched route.

| Routes | Request | Result |
|---|---|---|
| `GET /users/{id}` | `GET /users/42` | match, `id = "42"` |
| `GET /users/{id}` | `GET /users/` | 404 |
| `GET /users/{id}` | `GET /users/a%2Fb` | 404 (decoded `/` in a parameter) |
| `GET /users/me`, `GET /users/{id}` | `GET /users/me` | `/users/me` |
| `GET /a/{x}`, `GET /{y}/b` | `GET /a/b` | `/a/{x}` (first segment decides) |
| `GET /files/{path...}` | `GET /files/a/b.txt` | match, `path = "a/b.txt"` |
| `GET /users` | `GET /users/` | 404 |
| `GET /users` | `POST /users` | 405, `Allow: GET, HEAD, OPTIONS` |
| `GET /users` | `HEAD /users` | GET handler, empty body |
| `GET /users` | `OPTIONS /users` | 204, `Allow: GET, HEAD, OPTIONS` |
| `GET /users/{id}`, `GET /users/{name}` | build | error: same method and shape |
| `GET /users` | `GET /us%00ers` | 400 |

## Typed arguments

Handlers receive typed arguments instead of digging through the request, an idea taken from Rust's
axum extractors.

**Selection.** A handler parameter gets its value in exactly one way:

- its type is `ServerRequestInterface` or `RequestScope`, which are injected; or
- it has exactly one attribute implementing `ParameterExtractor`, such as `#[Path] int $id`,
  `#[Query('page')] int $page = 1` or `#[Header('X-Request-Id')] string $requestId`.

Nothing is inferred from a bare type or name, and attributes are instantiated only for parameters
of registered handlers.

**Planning.** `build()` reflects on every handler once. Each extractor's `plan()` receives the
parameter description, validates it and returns a resolver; requests run the resolvers without
reflection. Handler signatures are therefore checked before the first request:

| Problem | Example |
|---|---|
| No way to resolve a parameter | `function (int $id)` |
| More than one extractor attribute | `#[Path] #[Query] int $id` |
| Variadic or by-reference parameter | `#[Query] string ...$tags` |
| Missing, union or intersection type where a scalar is expected | `#[Path] int\|string $id` |
| Type not supported by the extractor | `#[Path] array $id` |
| `#[Path]` name not in the route template | `/users/{id}` with `#[Path('user')]` |
| More than one body extractor | `#[JsonBody] A $a, #[JsonBody] B $b` |
| Missing or unsupported return type | `function (): array` |

**Body ownership.** Extractors that consume the request body implement `BodyExtractor`. A handler
has at most one, in any position; it is resolved after every other parameter, so a request with an
invalid path, query or header is rejected before its body is read. The core ships none; JSON, form
and multipart extractors live in packages. Middleware and handlers that take
`ServerRequestInterface` can still read the body through PSR-7.

**Scalar parsing** (path, query, header and cookie extractors):

- Supported types are `string`, `int`, `bool`, backed enums, `DateTimeImmutable`, `LocalDate` and
  classes implementing `RequestValue`, optionally nullable. A `RequestValue` parses the raw string
  itself (returning null for invalid input) and describes its raw type and constraints, so a domain
  rule such as "a task ID is a positive integer" is written once. `Scalar::int()` and
  `Scalar::bool()` expose the strict rules below.
- `int` accepts only `-?(0|[1-9][0-9]*)` within the integer range: no `+`, whitespace, exponents or
  leading zeros. `bool` accepts `true`, `false`, `1` and `0`. A backed enum accepts its backing
  values.
- A missing value uses the parameter's default; with no default it is a 400, even for a nullable
  type. Nullability only allows a `null` default.
- Query parameters are read from the raw query string, not with `parse_str()`, which renames keys
  and keeps only the last of repeated keys. A repeated key for a single-value parameter is a 400.
  `+` decodes to a space.
- A header with more than one field value is a 400.

Every extraction failure is a 400 Problem Details response naming the parameter and the reason,
without echoing the received value.

**Return values.** A handler's declared return type must be `ResponseInterface`, `ResponseValue`, or
a subtype or union of those. `ResponseValue::toResponse()` builds the response from the
application's PSR-17 factories. The core ships `Text` and `NoContent`. Arrays, `null` and arbitrary
objects are not converted automatically, so a value holding secrets cannot be serialised by accident.

**Build cost under PHP-FPM.** PHP-FPM builds the application for every request. `build(lazy: true)`
plans each handler only when its route is requested, so a request pays for one plan instead of all;
handler errors then surface as a 500 at request time, so applications keep a test that runs the
eager `build()`. Caching plans with `serialize()` was measured and rejected: unserialising cost as
much as planning and would have added a deserialisation surface.

## Route and parameter metadata

OpenAPI generation, validation and tooling need to know an application's routes and what each handler
parameter reads, without the core knowing OpenAPI and without packages rediscovering routes.

- `AppBuilder::describe()` returns an `ApplicationDescription`. The built `Application` carries no
  description, because under PHP-FPM describing it on every request would cost time for nothing.
  `describe()` runs the same planning code as `build()`, so the two cannot drift, and reports the same
  errors.
- `RouteDescription`: method, template, name, template parameter names, described parameters, the
  names of undescribed parameters and the declared responses.
- `ParameterDescription`: source (`path`, `query`, `header`, `body`, or a string a package defines),
  external name, PHP parameter name, type, whether it is required, the default and its constraints.
- `Type` is a neutral model: `ScalarType`, `EnumType`, `ListType`, `MapType`, `ObjectType`,
  `UnionType`, `ConstType`, `FileType`, `EventStreamType` and `MixedType`, with a nullable
  flag. Nothing uses OpenAPI vocabulary.
- Extractors describe themselves by implementing `DescribesParameter`; one that does not is listed as
  undescribed rather than guessed at. Response classes describe themselves with `DescribesResponse`.
- **Constraints.** The core defines `Constraint`: an attribute on a parameter or DTO property that
  validates a converted value and describes itself with JSON Schema keywords. Path, query and header
  extractors apply constraints (a failure is a 400); body extractors apply them to properties.
  Concrete constraints live in `shirakaba/validation`.

```php
$app->get('/users/{id}', static fn (#[Path] #[Range(min: 1)] int $id, #[Query] bool $verbose = false): Text => ...)
    ->name('users.show');
```

describes as

```text
RouteDescription(method: GET, template: /users/{id}, name: users.show,
    templateParameters: [id],
    parameters: [
        ParameterDescription(source: path, name: id, parameter: id, type: int, required: true,
            constraints: [Range(min: 1)]),
        ParameterDescription(source: query, name: verbose, parameter: verbose, type: bool,
            required: false, default: false),
    ],
    undescribed: [], responses: [Text])
```

These types are public API used by several packages; adding a source (form bodies, cookies) needs no
core change.
