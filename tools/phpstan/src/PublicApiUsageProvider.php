<?php

declare(strict_types=1);

namespace Shirakaba\Tools\PHPStan;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnumUnitCase;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Tells the dead code detector what is used from outside the monorepo
 * (design/13-static-analysis-and-agents.md), so that it reports only code nothing can reach.
 *
 * - Public and protected members of package classes outside `Internal` namespaces and without
 *   `@internal` are the public API: applications use them, the monorepo need not.
 * - Properties and enum cases of classes in tests and examples are read and written by the
 *   framework through reflection: JSON and form DTOs, entities, template data.
 */
final class PublicApiUsageProvider extends ReflectionBasedMemberUsageProvider
{
    #[\Override]
    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        return !$method->isPrivate() ? $this->publicApi($method->getDeclaringClass()) : null;
    }

    #[\Override]
    protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData
    {
        return !$constant->isPrivate() ? $this->publicApi($constant->getDeclaringClass()) : null;
    }

    #[\Override]
    protected function shouldMarkEnumCaseAsUsed(ReflectionEnumUnitCase $enumCase): ?VirtualUsageData
    {
        return $this->publicApi($enumCase->getDeclaringClass()) ?? $this->reflected($enumCase->getDeclaringClass());
    }

    #[\Override]
    protected function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData
    {
        return $this->property($property);
    }

    #[\Override]
    protected function shouldMarkPropertyAsWritten(ReflectionProperty $property): ?VirtualUsageData
    {
        return $this->property($property);
    }

    private function property(ReflectionProperty $property): ?VirtualUsageData
    {
        $class = $property->getDeclaringClass();

        return (!$property->isPrivate() ? $this->publicApi($class) : null) ?? $this->reflected($class);
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function publicApi(ReflectionClass $class): ?VirtualUsageData
    {
        $file = $class->getFileName();
        if ($file === false || preg_match('#/packages/[^/]+/src/#', $file) !== 1) {
            return null;
        }
        if (in_array('Internal', explode('\\', $class->getNamespaceName()), true)) {
            return null;
        }
        $doc = $class->getDocComment();
        if ($doc !== false && preg_match('/@internal\b/', $doc) === 1) {
            return null;
        }

        return VirtualUsageData::withNote('Public API of a package');
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function reflected(ReflectionClass $class): ?VirtualUsageData
    {
        $file = $class->getFileName();
        if ($file === false || preg_match('#/(tests|examples)/#', $file) !== 1) {
            return null;
        }

        return VirtualUsageData::withNote('Read and written by the framework through reflection');
    }
}
