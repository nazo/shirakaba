# rate-limited

**Status 429.** The client made more requests of this kind than the limit allows in the current
window, such as sign-in attempts per address or posts per user. `Retry-After` says how many
seconds to wait; the `RateLimit` and `RateLimit-Policy` headers give the limit, what is left and
when the window resets.

- **Client:** wait for `Retry-After` seconds before trying again; do not retry in a loop.
- **Server:** limits are declared per route with `RateLimiter::limit()` or checked in a handler
  with `RateLimiter::hit()` (shirakaba/rate-limit).
