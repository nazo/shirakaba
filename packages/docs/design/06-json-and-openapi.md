# JSON, responses and OpenAPI

JSON is the main format of the APIs Shirakaba targets. `shirakaba/json` maps request bodies into
DTOs and DTOs into responses with one set of rules, and `shirakaba/openapi` describes both from
the same source.

## Request bodies

`#[JsonBody] CreateUser $input` maps the request body into a DTO: a class whose constructor
parameters define the fields. Options are `maxBytes` (default 1 MiB), `maxDepth` (default 64) and
`allowUnknownFields` (default false).

| Status | Cause |
|---|---|
| 415 | `Content-Type` is not `application/json` or `*/*+json` (parameters ignored) |
| 413 | `Content-Length` or the body read exceeds `maxBytes` |
| 400 | malformed JSON, invalid UTF-8 or nesting deeper than `maxDepth` |
| 400 | the document does not match the DTO |
| 422 | a mapped value violates a constraint |

Field errors are listed in the Problem Details member `errors`, each with a JSON Pointer, a code
and a detail; received values are never echoed.

**Mapping rules.** Fields are constructor parameters, matched by exact name, or by the name given
with `#[JsonName('per_page')]`.

- `string` and `bool` accept the same JSON type only; `int` a JSON integer only (no floats, no
  numeric strings); `float` any JSON number.
- Backed enums take the backing value; `DateTimeImmutable` and `LocalDate` take the formats of
  [date and time](07-date-and-time.md).
- Nested DTO classes map JSON objects. `array` parameters need `#[ListOf(type)]` (JSON arrays) or
  `#[MapOf(type)]` (JSON objects); a bare `array` is a build error, so nothing untyped
  slips through.
- `null` is accepted only for nullable parameters. A missing field uses the default, or is an
  error when there is none, also for nullable types.
- Unknown fields are an error unless `allowUnknownFields` is set, so clients learn about typos and
  nothing is silently dropped.
- Fields whose absence matters (partial updates) are declared as
  `#[OptionalOf(type, nullable)] Optional $field = new Optional()`. An absent field stays
  `new Optional()`; a sent one becomes `Optional::of($value)`, so absent, `null` and a value are
  distinguishable.
- The mapping plan is built from reflection once, at build time; requests run the plan.
- PHP's decoder keeps the last of duplicate keys; detecting duplicates would need a custom parser
  and is not done.

DTO validation is opt-in through constraint attributes ([routing and handlers](04-routing-and-handlers.md)).

## Responses

`new Json($data, $status)` encodes arrays, scalars, `null`, backed enums, dates, `#[JsonDto]`
objects and `JsonSerializable` objects. Any other object anywhere in the data is a 500, so an
entity holding secrets is never serialised by accident. Encoding uses `JSON_THROW_ON_ERROR`, so
invalid UTF-8 is an error rather than a silent substitution.

Response DTOs are marked `#[JsonDto]`: `Json` encodes them from their constructor fields (promoted
public properties, `#[JsonName]` applied), the same fields that describe them, so a type has one
source and cannot drift. The marker is an explicit opt-in.

### Declared responses

Return types tell which response classes a handler uses, not what is inside a `Json` body, which
status it uses or which errors it throws. Handlers declare these with attributes, read once at
build time:

```php
#[JsonResponse(201, Task::class)]
#[Responds(404)]
public function create(...): Json
```

- The core defines `ResponseDeclaration` and `Responds` (a status and an optional description;
  error statuses are Problem Details). `shirakaba/json` adds `JsonResponse(status, Type::class,
  list: bool)`, whose body type is derived with the mapping rules above, so response and request
  DTOs are described alike.
- Route middleware that implements `ResponseDeclaration` adds its response too; the rate limit
  middleware declares its 429 this way.
- A route that declares exactly one success status makes it the default status of `Json` and
  `Text`, so it is written once.
- When a handler declares success statuses and returns a different 2xx or 3xx, an `assert()` fails,
  so the mismatch shows up in development and tests and costs nothing in production.
- Declared errors are documentation: a handler may still throw an undeclared failure, which is
  covered by the `default` response.

### Tagged unions

An endpoint that returns several kinds of item (a notification that is a follow, a reaction or a
mention) would otherwise be one DTO with every field nullable, which clients cannot narrow.

- A union is declared with `#[JsonUnion(discriminator, [value => Member::class, ...])]` on the type
  the members share, usually an interface, so encoding, decoding and description use one mapping.
- Members are `#[JsonDto]` classes that do not declare the discriminator: `Json` writes it first
  from the mapping, and planning fails if a member has a field of that name. A class belongs to one
  union only. Discriminator values are strings.
- Decoding reads the discriminator and maps the object as that member; a missing or unknown one is
  a field error at `/<discriminator>`.
- Unions without a discriminator are not supported, since they cannot be decoded without guessing.

## OpenAPI

- Generation lives in `shirakaba/openapi`, never in the core. Its only input is the
  `ApplicationDescription` ([routing and handlers](04-routing-and-handlers.md)); it does not
  discover routes.
- It writes OpenAPI 3.1, whose schemas are JSON Schema 2020-12, so constraint keywords are copied
  as they are.
- Documents are generated ahead of time by `vendor/bin/shirakaba-openapi`, which loads a PHP file
  returning an `AppBuilder` (and optionally a generator configuration) and writes JSON. `--strict`
  fails when something could not be described. Serving the document is an optional middleware.
- Information the code cannot express (title, version, summaries, tags) is configured in PHP per
  route name. Docblocks are not parsed for behaviour.
- Every operation also documents the Problem Details responses the core can produce: 400 when it
  has parameters, 422 when a body has constraints, and a `default` problem response.
- A catch-all parameter (`{path...}`) is written as a single `{path}` parameter with a
  description, because OpenAPI paths cannot express multi-segment parameters.
- Undescribed parameters are reported as warnings and left out rather than guessed.
- Parameters with the source `bearer` become a security requirement, a `bearerAuth` scheme and a
  401 response ([security](09-security.md)).
- A union becomes a component with `oneOf` member references and a `discriminator` with an explicit
  `mapping`; each member's discriminator is a required `const`. Input and output components are
  separate, because required properties differ between them.
- Event streams and hub channels are described with extension keys
  ([real-time events](11-real-time-events.md)).

The tests validate a generated document against the official OpenAPI 3.1 schema with
`opis/json-schema` (development only), in its spec-compliant mode and with a static reference in
place of the schema's `$dynamicRef`, which the validator resolves to the wrong anchor.
