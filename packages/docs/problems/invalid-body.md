# invalid-body

**Status 400.** A JSON or form body does not match the expected structure: missing or unknown
fields, wrong types, values that cannot be parsed. Each problem is listed in `errors` with a JSON
Pointer, a `detail` and a field code ([required](required.md), [unknown-field](unknown-field.md),
[invalid-type](invalid-type.md), [invalid-date-time](invalid-date-time.md),
[invalid-date](invalid-date.md), [invalid-choice](invalid-choice.md)).

- **Client:** fix each field listed in `errors`.
- **Server:** the structure is the DTO of `#[JsonBody]` or `#[FormBody]`.
