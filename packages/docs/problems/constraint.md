# constraint

**Field error.** The value has the right type but breaks a constraint: a length, a range, a
pattern, an e-mail address, a file type, or the DTO's own check of the combination. The `detail`
says which.

- **Client:** correct the value as the `detail` describes; the OpenAPI document lists the limits
  (`minLength`, `maximum`, `pattern`, ...).
