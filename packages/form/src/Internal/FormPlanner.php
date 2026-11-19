<?php

declare(strict_types=1);

namespace Shirakaba\Form\Internal;

use BackedEnum;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use Shirakaba\Core\Description\DocComment;
use Shirakaba\Core\Extraction\Constraint;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Form\FormList;

/**
 * Builds the field list of a form DTO from reflection, once, at build time.
 *
 * @internal
 */
final readonly class FormPlanner
{
    /**
     * @param class-string $class
     * @return list<FormField>
     * @throws InvalidApplication when the class cannot be mapped from a form
     */
    public static function fields(string $class, string $context): array
    {
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new InvalidApplication(sprintf('%s: %s cannot be instantiated.', $context, $class));
        }

        $fields = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $where = sprintf('%s: %s::__construct() parameter $%s', $context, $class, $parameter->getName());
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $parameter->isVariadic()) {
                throw new InvalidApplication($where . ' needs a single declared type.');
            }

            $list = $type->getName() === 'array';
            $typeName = $type->getName();
            if ($list) {
                $formList = $parameter->getAttributes(FormList::class)[0] ?? null;
                if ($formList === null) {
                    throw new InvalidApplication($where . ' is an array; add #[FormList(...)] to declare its items.');
                }
                $typeName = $formList->newInstance()->type;
            }

            [$kind, $enum, $intBacked] = self::kind($typeName, $where);
            if ($list && $kind === 'file') {
                throw new InvalidApplication($where . ': lists of files are not supported yet.');
            }

            $hasDefault = $parameter->isDefaultValueAvailable();
            $fields[] = new FormField(
                $parameter->getName(),
                $kind,
                $list,
                $type->allowsNull(),
                $hasDefault,
                $hasDefault ? $parameter->getDefaultValue() : null,
                array_map(
                    static fn (ReflectionAttribute $attribute): Constraint => $attribute->newInstance(),
                    $parameter->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF),
                ),
                $enum,
                $intBacked,
                DocComment::ofParameter($parameter),
            );
        }

        return $fields;
    }

    /**
     * @return array{'string'|'int'|'float'|'bool'|'enum'|'file'|'instant'|'date', class-string<BackedEnum>|null, bool}
     */
    private static function kind(string $type, string $where): array
    {
        return match (true) {
            in_array($type, ['string', 'int', 'float', 'bool'], true) => [$type, null, false],
            $type === UploadedFileInterface::class => ['file', null, false],
            $type === \DateTimeImmutable::class => ['instant', null, false],
            $type === \Shirakaba\Time\LocalDate::class => ['date', null, false],
            is_a($type, \DateTimeInterface::class, true) => throw new InvalidApplication(sprintf(
                '%s is %s; use DateTimeImmutable for instants (design/07-date-and-time.md).',
                $where,
                $type,
            )),
            enum_exists($type) && is_subclass_of($type, BackedEnum::class) => ['enum', $type, (string) new ReflectionEnum($type)->getBackingType() === 'int'],
            default => throw new InvalidApplication(sprintf(
                '%s has the unsupported type %s; use string, int, float, bool, a backed enum, DateTimeImmutable, LocalDate, UploadedFileInterface or #[FormList] arrays.',
                $where,
                $type,
            )),
        };
    }
}
