# Getting started

Shirakaba needs PHP 8.5. The packages are not on Packagist yet, so add the Shirakaba
repository to the application's `composer.json` first ([principles](../design/01-principles.md)):

```json
"repositories": [{"type": "composer", "url": "https://nazo.github.io/shirakaba/"}]
```

Then install the core and a runtime:

```sh
composer require shirakaba/core shirakaba/runtime-fpm
```

An application is plain PHP. Keep the assembly in one file that returns the `AppBuilder`,
so the web entry point, the tests and tools such as the OpenAPI generator share it:

```php
<?php // app.php

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\Text;

$app = new AppBuilder();
$app->get('/hello/{name}', static fn (#[Path] string $name): Text => new Text('Hello, ' . $name));

return $app;
```

```php
<?php // public/index.php

use Shirakaba\Core\AppBuilder;
use Shirakaba\Runtime\Fpm\FpmRuntime;

require __DIR__ . '/../vendor/autoload.php';

$builder = require __DIR__ . '/../app.php';
assert($builder instanceof AppBuilder);

new FpmRuntime()->run($builder->build(lazy: true));
```

Try it with PHP's built-in server:

```sh
php -S 127.0.0.1:8080 public/index.php
curl http://127.0.0.1:8080/hello/world
```

There are no configuration files and nothing is discovered: everything the application
contains is in `app.php`, where PHPStan and your IDE can see it ([principles](../design/01-principles.md)).

`build()` checks every route and handler signature and fails with `InvalidApplication`
before the first request. `build(lazy: true)` postpones those checks to the first request of
each route, which keeps PHP-FPM fast; keep a test that calls `build()` so mistakes are still
caught (see [Testing](09-testing.md)).
