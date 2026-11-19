<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use ReflectionAttribute;
use ReflectionParameter;
use Shirakaba\Core\Extraction\Constraint;

/**
 * @internal
 */
final readonly class Constraints
{
    /**
     * @return list<Constraint>
     */
    public static function of(ReflectionParameter $parameter): array
    {
        return array_map(
            static fn (ReflectionAttribute $attribute): Constraint => $attribute->newInstance(),
            $parameter->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF),
        );
    }

    /**
     * @param list<Constraint> $constraints
     * @return string|null the first violation
     */
    public static function violation(array $constraints, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        foreach ($constraints as $constraint) {
            $violation = $constraint->violation($value);
            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }
}
