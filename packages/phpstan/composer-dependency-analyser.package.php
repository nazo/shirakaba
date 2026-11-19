<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// Exceptions to the checks of tools/monorepo/bin/test-standalone for this package.
return static function (Configuration $config): void {
    // PHPStan ships PHP-Parser and its testing functions inside phpstan.phar.
    $config->ignoreErrorsOnPackage('nikic/php-parser', [ErrorType::SHADOW_DEPENDENCY]);
    $config->ignoreUnknownFunctions(['PHPStan\Testing\assertType']);
};
