# OpenAPI

```sh
composer require --dev shirakaba/openapi
```

Documents are generated ahead of time from the same declarations that run the
application ([routing and handlers](../design/04-routing-and-handlers.md) and [JSON and OpenAPI](../design/06-json-and-openapi.md)):

```sh
vendor/bin/shirakaba-openapi app.php --config=openapi.php --output=openapi.json --strict
```

```php
<?php // openapi.php

return new Generator(
    new Info('Tasks', '1.0.0'),
    ['tasks.show' => new Operation(summary: 'Show a task', tags: ['tasks'])], // by route name
);
```

- Parameters, constraints and request bodies come from extractors and DTOs. A nullable
  path, query or header parameter is documented without null, since a request cannot
  carry one: with a `null` default it is optional, without one it is required.
- Doc comments on DTO properties (on the promoted constructor parameter, or the property
  of the same name) become property descriptions; tags such as `@var` are left out.
- Responses come from `#[JsonResponse]` and `#[Responds]` declarations, plus the Problem
  Details responses the core produces (400, 413, 415, 422, 401 and `default`).
- `#[Responds(200, contentType: 'application/activity+json')]` documents a body of another
  media type: any JSON value for JSON types, a string otherwise.
- `->undocumented()` on a route leaves it out, for endpoints of other protocols (WebFinger,
  ActivityPub) or internal ones.
- `#[Authenticated]` becomes a bearer security requirement.
- Anything that cannot be described is a warning and left out; `--strict` fails on
  warnings.

Commit the document and have a test regenerate and compare it, so it never drifts.
`ServeDocument` serves a pre-generated file if you want it online.
