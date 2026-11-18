# required

**Field error.** A field the DTO needs is missing from the body, or a union's discriminator is
missing.

- **Client:** send the field named by the pointer.
- **Server:** give the constructor parameter a default, or use `Optional`, to make it optional.
