# missing-parameter

**Status 400.** A required path, query, header or cookie parameter was not sent. The `detail`
names it, for example `Missing query parameter "page".`

- **Client:** send the parameter; the OpenAPI document marks required parameters.
- **Server:** give the handler parameter a default (`#[Query] int $page = 1`) or make it nullable
  to make it optional.
