# body-too-large

**Status 413.** The body exceeds the endpoint's size limit, has too many object members or form
fields, or an uploaded file exceeds the upload limit.

- **Client:** send less, or split the request.
- **Server:** limits are options of `#[JsonBody]` (`maxBytes`, `maxMembers`) and `#[FormBody]`, and
  PHP's upload settings.
