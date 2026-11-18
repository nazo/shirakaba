<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Monorepo;

/**
 * Checks that every package under packages/ is consistent with the root manifest.
 *
 * Rules enforced:
 *  - each package is named "shirakaba/<directory>" and shares the root license, PHP constraint and branch alias;
 *  - internal dependencies point at packages that exist in this repository and use the current minor constraint;
 *  - external dependencies of a package are declared in the root with an identical constraint;
 *  - the root runtime "require" contains nothing beyond PHP and the union of package dependencies,
 *    extensions included, so no dependency can slip in without a package that actually needs it;
 *  - the root "replace" lists exactly the packages that exist;
 *  - root autoload entries under packages/ match the packages' own entries in both directions;
 *  - the database packages require no framework package, only each other and shirakaba/time,
 *    so they stay usable on their own (design/08-database.md).
 */
final readonly class Validator
{
    private const string VENDOR = 'shirakaba';

    /**
     * Packages usable without the framework, and the internal packages they may require
     * besides each other (design/08-database.md).
     */
    private const array LIBRARIES = ['database', 'query', 'query-cache', 'migrations', 'orm'];
    private const array LIBRARY_DEPENDENCIES = ['time'];

    public function __construct(
        private string $rootDir,
    ) {}

    /**
     * @return list<string> human readable violations; empty when the repository is consistent
     * @throws ValidationFailure when a manifest cannot be loaded or has values of the wrong type
     */
    public function validate(): array
    {
        $root = ComposerJson::load($this->rootDir . '/composer.json');
        $packages = $this->loadPackages();
        $errors = [];

        $rootRequire = $root->stringMap('require');
        $rootRequireDev = $root->stringMap('require-dev');
        $rootPhp = $rootRequire['php'] ?? null;
        if ($rootPhp === null) {
            $errors[] = 'Root composer.json must require "php".';
        }

        $branchAlias = $root->stringMap('extra', 'branch-alias')['dev-main'] ?? null;
        $internalConstraint = $branchAlias === null ? null : self::constraintFromBranchAlias($branchAlias);
        if ($internalConstraint === null) {
            $errors[] = 'Root composer.json must define extra.branch-alias.dev-main as "<major>.<minor>.x-dev".';
        }

        $usedExternal = [];
        /** @var array{autoload: array<string, string>, autoload-dev: array<string, string>} $expectedAutoload */
        $expectedAutoload = ['autoload' => [], 'autoload-dev' => []];

        foreach ($packages as $directory => $package) {
            $name = self::VENDOR . '/' . $directory;
            $label = sprintf('packages/%s/composer.json', $directory);

            if ($package->string('name') !== $name) {
                $errors[] = sprintf('%s: "name" must be "%s".', $label, $name);
            }
            if ($package->string('license') !== $root->string('license')) {
                $errors[] = sprintf('%s: "license" must match the root license.', $label);
            }
            // Each package is distributed on its own, so it carries the licence text itself.
            $license = $this->rootDir . '/LICENSE';
            $copy = $this->rootDir . '/packages/' . $directory . '/LICENSE';
            if (is_file($license) && (!is_file($copy) || file_get_contents($copy) !== file_get_contents($license))) {
                $errors[] = sprintf('packages/%s/LICENSE must be a copy of the root LICENSE.', $directory);
            }
            if (($package->stringMap('extra', 'branch-alias')['dev-main'] ?? null) !== $branchAlias) {
                $errors[] = sprintf('%s: extra.branch-alias.dev-main must match the root.', $label);
            }

            $require = $package->stringMap('require');
            if (($require['php'] ?? null) !== $rootPhp) {
                $errors[] = sprintf('%s: "require.php" must be "%s".', $label, $rootPhp ?? '');
            }

            foreach ($require as $dependency => $constraint) {
                if ($dependency === 'php') {
                    continue;
                }

                if (str_starts_with($dependency, self::VENDOR . '/')) {
                    $dependencyDirectory = substr($dependency, strlen(self::VENDOR) + 1);
                    if (!isset($packages[$dependencyDirectory])) {
                        $errors[] = sprintf('%s: depends on unknown internal package "%s".', $label, $dependency);
                    } elseif (in_array($directory, self::LIBRARIES, true) && !in_array($dependencyDirectory, [...self::LIBRARIES, ...self::LIBRARY_DEPENDENCIES], true)) {
                        $errors[] = sprintf('%s: must be usable without the framework, so it cannot require "%s"; move the integration to shirakaba/database-bridge (design/08-database.md).', $label, $dependency);
                    } elseif ($internalConstraint !== null && $constraint !== $internalConstraint) {
                        $errors[] = sprintf('%s: internal dependency "%s" must use "%s".', $label, $dependency, $internalConstraint);
                    }
                    continue;
                }

                $usedExternal[$dependency] = true;
                // Tooling packages (such as PHPStan extensions) depend on what the root needs for development only.
                $rootConstraint = $rootRequire[$dependency] ?? $rootRequireDev[$dependency] ?? null;
                if ($rootConstraint === null) {
                    $errors[] = sprintf('%s: "%s" must also be required (or required for development) by the root composer.json.', $label, $dependency);
                } elseif ($rootConstraint !== $constraint) {
                    $errors[] = sprintf('%s: "%s" constraint "%s" differs from the root "%s".', $label, $dependency, $constraint, $rootConstraint);
                }
            }

            foreach (['autoload', 'autoload-dev'] as $section) {
                foreach ($package->stringMap($section, 'psr-4') as $prefix => $path) {
                    if (isset($expectedAutoload[$section][$prefix])) {
                        $errors[] = sprintf('%s: %s.psr-4 prefix "%s" is already used by another package.', $label, $section, $prefix);
                        continue;
                    }
                    $expectedAutoload[$section][$prefix] = sprintf('packages/%s/%s', $directory, $path);
                }
            }
        }

        foreach (array_keys($rootRequire) as $dependency) {
            if ($dependency !== 'php' && !isset($usedExternal[$dependency])) {
                $errors[] = sprintf('Root composer.json requires "%s", but no package needs it.', $dependency);
            }
        }

        $expectedReplace = [];
        foreach (array_keys($packages) as $directory) {
            $expectedReplace[self::VENDOR . '/' . $directory] = 'self.version';
        }
        $actualReplace = $root->stringMap('replace');
        ksort($expectedReplace);
        ksort($actualReplace);
        if ($expectedReplace !== $actualReplace) {
            $errors[] = sprintf(
                'Root composer.json "replace" must be exactly %s.',
                json_encode($expectedReplace, JSON_UNESCAPED_SLASHES),
            );
        }

        foreach ($expectedAutoload as $section => $expected) {
            $errors = [...$errors, ...self::compareAutoload($section, $expected, $root->stringMap($section, 'psr-4'))];
        }

        return $errors;
    }

    /**
     * @return array<string, ComposerJson> keyed by package directory name, sorted
     */
    private function loadPackages(): array
    {
        $directories = glob($this->rootDir . '/packages/*', GLOB_ONLYDIR);
        $packages = [];
        foreach ($directories === false ? [] : $directories as $directory) {
            $packages[basename($directory)] = ComposerJson::load($directory . '/composer.json');
        }
        ksort($packages);

        return $packages;
    }

    /**
     * Compares root entries that point into packages/ with the entries the packages declare.
     * Root entries elsewhere (such as tools/) are the root's own business.
     *
     * @param array<string, string> $expected
     * @param array<string, string> $actual
     * @return list<string>
     */
    private static function compareAutoload(string $section, array $expected, array $actual): array
    {
        $errors = [];
        foreach ($expected as $prefix => $path) {
            if (($actual[$prefix] ?? null) !== $path) {
                $errors[] = sprintf('Root %s.psr-4 must map "%s" to "%s".', $section, $prefix, $path);
            }
        }
        foreach ($actual as $prefix => $path) {
            if (str_starts_with($path, 'packages/') && !isset($expected[$prefix])) {
                $errors[] = sprintf('Root %s.psr-4 maps "%s" to "%s", but no package declares it.', $section, $prefix, $path);
            }
        }

        return $errors;
    }

    /**
     * Not marked pure: PHPStan treats preg_match() with a by-reference $matches as possibly impure,
     * although the mutation never escapes this method.
     */
    private static function constraintFromBranchAlias(string $alias): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)\.x-dev$/', $alias, $matches) !== 1) {
            return null;
        }

        return sprintf('^%s.%s', $matches[1], $matches[2]);
    }
}
