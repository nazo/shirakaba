# invalid-type

**Field error.** The value has the wrong JSON type (a string where a number is expected, an
array where an object is expected) or a structure the endpoint does not accept (a repeated form
field, a nested multipart part).

- **Client:** send the type the OpenAPI document declares. JSON numbers are not strings: send
  `5`, not `"5"`.
