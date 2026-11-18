Add an HTTP endpoint to this application: `GET /greet` with a required query parameter `name`
of 1 to 50 characters. It answers 200 with the JSON object `{"greeting": "Hello, <name>!"}`.
A request without `name`, or with an empty or longer one, must be rejected with the
framework's error response. Declare the response so it appears in the OpenAPI description,
add a test, and make sure `composer check` passes.
