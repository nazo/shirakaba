<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// Exceptions to the checks of tools/monorepo/bin/test-standalone for this package.
return static function (Configuration $config): void {
    // The request factory of the public API returns a PSR-7 request, named in its PHPDoc type only.
    $config->ignoreErrorsOnPackage('psr/http-message', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV]);
};
