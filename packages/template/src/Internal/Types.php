<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Property types for TemplateChecker, from declarations and `list<T>` PHPDoc.
 *
 * @internal
 */
final readonly class Types
{
    /**
     * @return list<array{string, mixed}>
     */
    public static function of(ReflectionProperty $property): array
    {
        $declared = $property->getType();
        $names = match (true) {
            $declared instanceof ReflectionNamedType => [
                $declared->getName(),
                ...($declared->allowsNull() && $declared->getName() !== 'null' && $declared->getName() !== 'mixed' ? ['null'] : []),
            ],
            $declared instanceof ReflectionUnionType => array_map(static fn ($type): string => $type instanceof ReflectionNamedType
                ? $type->getName()
                : 'mixed', $declared->getTypes()),
            default => ['mixed'],
        };
        $atoms = [];
        foreach ($names as $name) {
            if ($name === 'array') {
                $items = self::listItems($property);
                $atoms[] = $items === null ? ['scalar', 'array'] : ['list', $items];
            } elseif (in_array($name, ['string', 'int', 'bool', 'float', 'null', 'false', 'true'], true)) {
                $atoms[] = ['scalar', in_array($name, ['false', 'true'], true) ? 'bool' : $name];
            } elseif ($name === 'mixed' || $name === 'object' || $name === 'iterable') {
                $atoms[] = ['mixed', ''];
            } else {
                $atoms[] = ['class', $name];
            }
        }

        return $atoms;
    }

    /**
     * The item types of `list<T>` (or `array<int, T>`, `T[]`) declared for the property, or null.
     *
     * @return list<array{string, mixed}>|null
     */
    private static function listItems(ReflectionProperty $property): ?array
    {
        $doc = (string) $property->getDocComment();
        $pattern = '/@var\s+(\S+)/';
        if ($doc === '' && $property->isPromoted()) {
            $doc = (string) $property->getDeclaringClass()->getConstructor()?->getDocComment();
            $pattern = '/@param\s+(\S+)\s+\$' . preg_quote($property->getName(), '/') . '\b/';
        }
        if (preg_match($pattern, $doc, $match) !== 1) {
            return null;
        }
        $type = $match[1] ?? '';
        if (preg_match('/\A(?:\??(?:non-empty-)?list<(.+)>|array<int,\s*(.+)>|([\\\\A-Za-z0-9_]+)\[\])\z/', $type, $inner) !== 1) {
            return null;
        }
        $item = implode('', array_slice($inner, 1));
        $atoms = [];
        foreach (explode('|', $item) as $member) {
            $member = trim($member);
            $atoms[] = match (true) {
                in_array($member, ['string', 'int', 'bool', 'float', 'null'], true) => ['scalar', $member],
                default => ($class = self::resolveClass($member, $property->getDeclaringClass())) === null ? ['mixed', ''] : ['class', $class],
            };
        }

        return $atoms;
    }

    /**
     * @param ReflectionClass<object> $context
     */
    private static function resolveClass(string $name, ReflectionClass $context): ?string
    {
        if (str_starts_with($name, '\\')) {
            $candidate = substr($name, 1);

            return class_exists($candidate) || interface_exists($candidate) ? $candidate : null;
        }
        $file = $context->getFileName();
        $source = $file === false ? '' : (string) @file_get_contents($file);
        [$head] = explode('\\', $name, 2);
        if (preg_match_all('/^use\s+([\\\\A-Za-z0-9_]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $uses, PREG_SET_ORDER) > 0) {
            foreach ($uses as $use) {
                $alias = ($use[2] ?? '') !== '' ? $use[2] : substr((string) strrchr('\\' . $use[1], '\\'), 1);
                if ($alias === $head) {
                    $candidate = $use[1] . substr($name, strlen($head));

                    return class_exists($candidate) || interface_exists($candidate) ? $candidate : null;
                }
            }
        }
        $candidate = $context->getNamespaceName() . '\\' . $name;

        return class_exists($candidate) || interface_exists($candidate) ? $candidate : null;
    }
}
