# Problem codes

Errors Shirakaba sends to clients are RFC 9457 Problem Details with a stable `code` member, and
each entry of `errors` (one per field) has its own `code`. Branch on codes, not on
the English `detail`, which may change. The codes are listed in `Shirakaba\Core\Http\ProblemCode`.

## Problems

| Code | Status | Meaning |
| --- | --- | --- |
| [missing-parameter](missing-parameter.md) | 400 | A required path, query, header or cookie parameter is missing |
| [invalid-parameter](invalid-parameter.md) | 400 | A parameter has the wrong type or breaks a constraint |
| [repeated-parameter](repeated-parameter.md) | 400 | A single-valued parameter appears more than once |
| [invalid-body](invalid-body.md) | 400 | The body does not have the expected structure; see `errors` |
| [invalid-values](invalid-values.md) | 422 | The body is well-formed but values break constraints; see `errors` |
| [malformed-body](malformed-body.md) | 400 | The body is not valid JSON |
| [unsupported-media-type](unsupported-media-type.md) | 415 | The body has a media type the endpoint does not accept |
| [body-too-large](body-too-large.md) | 413 | The body, a file or the number of fields exceeds a limit |
| [rate-limited](rate-limited.md) | 429 | Too many requests of this kind in the current window |

A parameter that should be a date-time or a date gets [invalid-date-time](invalid-date-time.md)
or [invalid-date](invalid-date.md) as its problem code instead of `invalid-parameter`.

## Field errors

| Code | Meaning |
| --- | --- |
| [required](required.md) | The field is missing |
| [unknown-field](unknown-field.md) | The field is not part of the request |
| [invalid-type](invalid-type.md) | The value has the wrong JSON type or structure |
| [invalid-date-time](invalid-date-time.md) | The value is not an RFC 3339 date-time with an offset |
| [invalid-date](invalid-date.md) | The value is not a YYYY-MM-DD date |
| [invalid-choice](invalid-choice.md) | The value is not one of the allowed values |
| [constraint](constraint.md) | The value breaks a constraint such as a length or a range |
