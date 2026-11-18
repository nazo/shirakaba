# JSON APIs

```sh
composer require shirakaba/json shirakaba/validation
```

## Request bodies

```php
final readonly class NewTask
{
    public function __construct(
        #[NotBlank] #[Length(max: 200)]
        public string $title,
        #[Length(max: 2000)]
        public string $description = '',
        #[ListOf('string')] #[Count(max: 10)]
        public array $tags = [],
    ) {}
}

public function create(#[JsonBody] NewTask $input): Json
```

The DTO's constructor defines the accepted fields ([JSON and OpenAPI](../design/06-json-and-openapi.md)): scalar types, backed enums,
nested DTOs, arrays with `#[ListOf(...)]`, and objects with arbitrary keys with
`#[MapOf(...)]` (`#[MapOf('int')] array $reactions` is `{"👍": 2}`; `Json` writes `{}` when
it is empty, and OpenAPI uses `additionalProperties`). Unknown fields are rejected unless
`#[JsonBody(allowUnknownFields: true)]`; `#[JsonName('per_page')]` renames a field.

| Status | When |
|---|---|
| 415 | `Content-Type` is not JSON |
| 413 | the body exceeds `maxBytes` (1 MiB by default) or has more than `maxMembers` object members (10,000) |
| 400 | malformed JSON, or fields of the wrong type, missing or unknown |
| 422 | a constraint failed |

Field errors are listed in the Problem Details member `errors` with JSON Pointers.

### Partial updates

Use `Optional` where "not sent" differs from "null" or a default:

```php
final readonly class TaskPatch
{
    public function __construct(
        #[OptionalOf('string')] #[NotBlank]
        public Optional $title = new Optional(),
        #[OptionalOf(TaskStatus::class)]
        public Optional $status = new Optional(),
    ) {}
}

$updated = new Task($task->id, $patch->title->or($task->title), ...);
```

## Responses

Mark response DTOs `#[JsonDto]` and `Json` encodes them from their constructor fields, the
same fields OpenAPI describes ([JSON and OpenAPI](../design/06-json-and-openapi.md)):

```php
#[JsonDto]
final readonly class Task
{
    public function __construct(public int $id, public string $title, public TaskStatus $status) {}
}

#[JsonResponse(201, Task::class)]
#[Responds(409, 'A task with this title exists.')]
public function create(#[JsonBody] NewTask $input, #[Db] TaskCommands $tasks): Json
{
    $task = $tasks->add($input->title);

    return new Json($task, headers: ['Location' => '/tasks/' . $task->id]); // 201 from the declaration
}
```

`Json` also accepts arrays, scalars, backed enums and `JsonSerializable` objects. Any other
object is refused with a 500, so entities holding secrets are never serialised by accident.
When a route declares exactly one success status, `Json` uses it by default; returning a
different success status fails an assertion in development.

## Tagged unions

When one endpoint returns several shapes, declare them as a tagged union on the type they
share ([JSON and OpenAPI](../design/06-json-and-openapi.md)). The attribute maps each discriminator value to a `#[JsonDto]` member:

```php
#[JsonUnion('type', [
    'follow' => FollowNotification::class,
    'reaction' => ReactionNotification::class,
])]
interface Notification {}

#[JsonDto]
final readonly class FollowNotification implements Notification
{
    public function __construct(public string $id, public UserSummary $user) {}
}

#[JsonDto]
final readonly class ReactionNotification implements Notification
{
    public function __construct(public string $id, public UserSummary $user, public string $reaction) {}
}

#[JsonResponse(200, Notification::class, list: true)]
public function list(): Json { /* ... */ }
```

`Json` writes each member with its discriminator first (`{"type":"follow","id":...}`), so
members do not declare a `type` field themselves; the build fails if one does. OpenAPI
describes the union as `oneOf` its members with a `discriminator` and mapping, and each
member's `type` as a `const`, so generated clients can narrow on it.

The same union works as a `#[JsonBody]`, a `#[ListOf]` item, a nullable field, an ORM
`#[Json]` column and with `JsonMapper::decode()`: the discriminator picks the member class,
and a missing or unknown value is a 400 pointing at `/type`.
