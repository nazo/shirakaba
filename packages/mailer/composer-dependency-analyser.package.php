<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// Exceptions to the checks of tools/monorepo/bin/test-standalone for this package.
return static function (Configuration $config): void {
    // TLS, the default, goes through stream functions that need ext-openssl without naming it.
    $config->ignoreErrorsOnExtension('ext-openssl', [ErrorType::UNUSED_DEPENDENCY]);
};
