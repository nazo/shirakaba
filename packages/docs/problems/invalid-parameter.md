# invalid-parameter

**Status 400.** A path, query, header or cookie parameter has a value of the wrong type (`page=x`
for an integer, an unknown enum value) or breaks a constraint (`#[Range]`, `#[Length]`). The
`detail` names the parameter and what was expected; the received value is never echoed.

- **Client:** send a value of the documented type and range.
- **Server:** the expectation comes from the handler parameter's type and constraint attributes.

Dates and date-times get [invalid-date-time](invalid-date-time.md) or
[invalid-date](invalid-date.md) instead.
