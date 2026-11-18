# mutable-date-time: use DateTimeImmutable for instants

JSON DTOs, entities and extractors take instants as `DateTimeImmutable` and calendar dates as
`LocalDate` ([date and time](../design/07-date-and-time.md)). `DateTime` is mutable, so a value shared between objects can change
under them, and `DateTimeInterface` does not say which one arrives.

## Fixes

**A. Declare `DateTimeImmutable` for a point in time.** JSON reads it from an RFC 3339
string with an offset (`2026-11-11T09:30:00Z`) and writes it in UTC.

**B. Declare `LocalDate` for a calendar date.** Birthdays, due dates and other values without
a time of day: `2026-11-11` in JSON, a `DATE` column in the database.

## Example

```php
#[JsonDto]
final readonly class TaskView
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
        public ?LocalDate $dueOn,
    ) {}
}
```

Take the current time from a PSR-20 clock (`ClockInterface::now()`), not `new
DateTimeImmutable()`, so tests can freeze it.

## Common mistakes

- Accepting date-times without an offset: Shirakaba rejects `2026-11-11T09:30:00` because it
  does not say which instant it is.
- Storing local wall-clock times: instants are stored and returned in UTC.
