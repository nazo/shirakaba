<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// Exceptions to the checks of tools/monorepo/bin/test-standalone for this package.
return static function (Configuration $config): void {
    // #[Json] columns use the optional shirakaba/json, which reports bad DTO types with the
    // InvalidApplication of shirakaba/core, a dependency of shirakaba/json.
    $config->ignoreErrorsOnPackageAndPath('shirakaba/core', __DIR__ . '/src/Mapping/EntityMap.php', [ErrorType::SHADOW_DEPENDENCY]);
};
