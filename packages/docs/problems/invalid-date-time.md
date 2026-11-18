# invalid-date-time

**Field error, or problem code of a parameter.** The value is not an RFC 3339 date-time with an
offset. Shirakaba requires the offset because `2026-11-11T09:30:00` does not say which instant it
is.

- **Client:** send `2026-11-11T09:30:00Z` (UTC) or `2026-11-11T18:30:00+09:00`. In JavaScript,
  `new Date().toISOString()` produces a valid value; a `datetime-local` input does not, so convert
  it first.
- **Server:** a value that is a calendar date without a time belongs in a `LocalDate`, which takes
  `YYYY-MM-DD` ([invalid-date](invalid-date.md)).
