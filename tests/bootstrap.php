<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Packages may need global test setup, such as constants read by third-party test suites.
$packageBootstraps = glob(dirname(__DIR__) . '/packages/*/tests/bootstrap.php');
foreach ($packageBootstraps === false ? [] : $packageBootstraps as $packageBootstrap) {
    require $packageBootstrap;
}
