<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan;

use PHPStan\Analyser\Scope;

/**
 * Which files the long-running safety rules check, configured with
 * `parameters.shirakaba.longRunningPaths` (regular expressions; empty for every file).
 * Tests often keep static state in fixtures, so applications usually limit this to src/.
 */
final readonly class PackageSource
{
    /**
     * @param list<string> $patterns
     */
    public function __construct(
        private array $patterns = [],
    ) {}

    public function contains(Scope $scope): bool
    {
        if ($this->patterns === []) {
            return true;
        }
        $file = str_replace('\\', '/', $scope->getFile());
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $file) === 1) {
                return true;
            }
        }

        return false;
    }
}
