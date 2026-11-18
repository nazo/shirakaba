# invalid-values

**Status 422.** The body has the right structure, but values break constraints (`#[Length]`,
`#[Range]`, `#[Email]`, ...) or the DTO rejected the combination. Each entry of `errors` has the
code [constraint](constraint.md).

- **Client:** correct the listed values.
- **Server:** constraints are attributes on the DTO's constructor parameters.
