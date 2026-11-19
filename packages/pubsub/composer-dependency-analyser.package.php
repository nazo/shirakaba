<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// Exceptions to the checks of tools/monorepo/bin/test-standalone for this package.
return static function (Configuration $config): void {
    // The Mercure publisher builds and sends PSR-7 requests, made by PSR-17 factories, without naming them.
    $config->ignoreErrorsOnPackage('psr/http-message', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV]);
};
