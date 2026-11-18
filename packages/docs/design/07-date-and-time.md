# Date and time

Time values cross every boundary of a web application: request bodies and parameters, responses,
databases, logs and tokens. PHP's defaults make mistakes easy: `DateTime` is mutable, parsing
accepts nearly anything and fills in missing parts from the current time and `date.timezone`, and
there is no type for a calendar date. A string such as `2026-11-11T09:30:00` names a different
instant on every server. `shirakaba/time` (which depends only on `psr/clock`) and the mapping rules
below remove these mistakes.

## Rules

- **Instants are `DateTimeImmutable`.** `DateTime` and `DateTimeInterface` parameters and DTO fields
  are rejected when the application is built.
- **The wall clock is a PSR-20 clock.** `Shirakaba\Time\SystemClock` always answers in UTC;
  `FrozenClock` serves tests. Durations and deadlines use `hrtime()`. A PHPStan rule reports other
  reads of the wall clock (`time()`, `new DateTimeImmutable()`).
- **Nothing depends on `date.timezone`.** Instants are normalised to UTC when parsed and formatted in
  UTC. Where a timezone matters (what day it is for a user), it is an explicit argument.
- **Input is strict RFC 3339 with an offset**: `2026-11-11T09:30:00+09:00` or `...Z`. A value without
  an offset, with `-00:00` ("offset unknown"), with a space for `T`, a leap second or an impossible
  date is invalid (a 400 with a field error), never read in a default timezone. Fractions beyond
  microseconds are truncated.
- **Output is RFC 3339 in UTC with `Z` and always six fractional digits**
  (`2026-11-11T00:30:00.000000Z`), so the strings sort in time order as text.
- **Calendar dates are `Shirakaba\Time\LocalDate`** (`YYYY-MM-DD`, years 0001 to 9999). Conversions
  to and from instants name the timezone (`fromInstant()`, `atStartOfDay()`, `today()`).
- **Supported wherever values are mapped**: path, query, header and cookie parameters, JSON bodies
  and responses, forms, console arguments, database columns and OpenAPI (`format: date-time` and
  `format: date`).
- **Local date-times are not supported yet.** `<input type="datetime-local">` sends no offset;
  applications read it as a string and convert it with an explicit timezone.
- **Databases store instants in UTC**: the session timezone is fixed to UTC, with `timestamptz` on
  PostgreSQL, `DATETIME(6)` on MySQL and ISO 8601 text on SQLite.
- Fixed date bounds are a constraint (`#[DateRange]`). Rules relative to now are checked where a
  clock is available (handlers, services), not in attributes, which cannot receive one; tests freeze
  the clock.

## Alternatives not taken

- **Accepting offset-less input in the server's timezone**: the same request would mean different
  instants on different servers, and the error would surface far from its cause.
- **Midnight UTC `DateTimeImmutable` for dates**: shown in a timezone west of UTC, it falls on the
  previous day.
- **A third-party date library**: a dependency for a small, well-defined need.

Clients must send offsets. JavaScript's `toISOString()` does; hand-written values without `Z` are
rejected with a message naming the format.
