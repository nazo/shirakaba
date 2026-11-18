# Reading request data

Handler parameters say where their value comes from with an attribute ([routing and handlers](../design/04-routing-and-handlers.md)):

```php
public function search(
    #[Path] int $categoryId,
    #[Query] string $q,
    #[Query('per_page')] int $perPage = 20,
    #[Query] ?Sort $sort = null,              // a backed enum
    #[Header('Accept-Language')] string $language = 'en',
    #[Cookie('theme')] string $theme = 'light',
): Json
```

- Supported types are `string`, `int`, `bool`, backed enums, `DateTimeImmutable`,
  `Shirakaba\Time\LocalDate` and `RequestValue` classes, optionally nullable.
- Parsing is strict: `int` rejects `+1`, `01`, `1.0` and overflow; `bool` accepts `true`,
  `false`, `1` and `0`.
- A missing value uses the default; without one it is a 400, even for nullable types.
- A repeated query key or header for a single value is a 400.
- Every failure is a 400 Problem Details response naming the parameter, without echoing
  the value.

## Dates and times

Instants are `DateTimeImmutable` and must be RFC 3339 with an offset
(`2026-11-11T09:30:00+09:00` or `...Z`); without one the request is a 400, never read in
a server timezone. They arrive normalised to UTC. Calendar dates are `LocalDate`
(`2026-11-11`). JSON bodies, `Json` responses and forms use the same rules, responses
write instants in UTC with `Z` and six fractional digits (`2026-11-11T00:30:00.000000Z`),
so they sort in time order as text, and OpenAPI shows `format: date-time` and `date`
([date and time](../design/07-date-and-time.md)).

`#[DateRange(notBefore: '1900-01-01')]` (from `shirakaba/validation`) bounds dates and
instants. Rules relative to now ("in the future") belong in the handler, which can take a
clock.

For "now", take a `Psr\Clock\ClockInterface` (`Shirakaba\Time\SystemClock`, or
`FrozenClock` in tests) rather than calling `time()` or `new DateTimeImmutable()`.

## Constraints

`shirakaba/validation` adds constraint attributes that work on these parameters and on DTO
fields alike:

```php
#[Query] #[Range(min: 1, max: 100)] int $perPage = 20,
#[Query] #[Length(min: 2)] string $q,
#[Query] #[OneOf(['asc', 'desc'])] string $order = 'asc',
```

Available: `Length`, `Range`, `Count`, `Pattern`, `NotBlank`, `OneOf`, `Email`. They also
describe themselves as JSON Schema keywords in OpenAPI documents.

## Value objects

When a rule belongs to a domain type, write it once in a class implementing
`RequestValue`:

```php
final readonly class TaskId implements RequestValue
{
    private function __construct(public int $value) {}

    public static function fromRequestValue(string $value): ?static
    {
        $id = Scalar::int($value);

        return $id !== null && $id >= 1 ? new static($id) : null;
    }

    public static function requestValueType(): Type
    {
        return new ScalarType('int');
    }

    public static function requestValueConstraints(): array
    {
        return [new Range(min: 1)];
    }
}

public function show(#[Path] TaskId $id): Json
```

## Writing your own extractor

An extractor is an attribute implementing `ParameterExtractor`. `plan()` runs once at build
time, validates the parameter, and returns an `ArgumentResolver` used for every request.
Implement `DescribesParameter` too, so OpenAPI can document it. Keep resolvers free of
request state.
