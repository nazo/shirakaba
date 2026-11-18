<?php

declare(strict_types=1);

use Shirakaba\Core\AppBuilder;
use Shirakaba\Runtime\Fpm\FpmRuntime;

require __DIR__ . '/../../../vendor/autoload.php';

$builder = require __DIR__ . '/../app.php';
assert($builder instanceof AppBuilder);

// Under PHP-FPM every request builds the application; lazy planning keeps that cheap.
// The test suite builds eagerly, so handler mistakes are still caught before deployment.
new FpmRuntime()->run($builder->build(lazy: true));
