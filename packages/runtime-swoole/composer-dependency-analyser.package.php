<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

// Exceptions to the checks of tools/monorepo/bin/test-standalone for this package.
return static function (Configuration $config): void {
    // The classes of ext-swoole, which is suggested: the analysis runs without the extension.
    $config->ignoreUnknownClassesRegex('~^Swoole\\\\~');
};
