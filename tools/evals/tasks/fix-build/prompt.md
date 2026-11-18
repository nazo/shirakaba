`composer check` fails in this project. Find the cause and fix it without removing the
`GET /users/{id}` route: it must answer 200 with the user as JSON `{"id": ..., "name": ...}`,
and 404 when there is no such user. Make sure `composer check` passes.
