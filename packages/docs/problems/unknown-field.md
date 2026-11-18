# unknown-field

**Field error.** The body has a field the DTO does not declare. Shirakaba rejects unknown fields so
that typos do not pass silently.

- **Client:** remove the field, or check its spelling (field names are case-sensitive and follow
  the API's names, which may differ from the server's property names).
- **Server:** `#[JsonBody(allowUnknownFields: true)]` accepts and ignores them.
