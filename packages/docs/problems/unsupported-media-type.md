# unsupported-media-type

**Status 415.** The body's `Content-Type` is not one the endpoint accepts: JSON endpoints take
`application/json` (or any `+json` type), form endpoints `application/x-www-form-urlencoded` or
`multipart/form-data`. The `Accept` header of the response names what is accepted where it can.

- **Client:** send the documented media type in `Content-Type`.
