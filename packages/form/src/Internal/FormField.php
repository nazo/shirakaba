<?php

declare(strict_types=1);

namespace Shirakaba\Form\Internal;

use BackedEnum;
use Shirakaba\Core\Extraction\Constraint;

/**
 * One constructor parameter of a form DTO.
 *
 * @internal
 */
final readonly class FormField
{
    /**
     * @param 'string'|'int'|'float'|'bool'|'enum'|'file'|'instant'|'date' $kind
     * @param class-string<BackedEnum>|null $enum
     * @param list<Constraint> $constraints
     */
    public function __construct(
        public string $name,
        public string $kind,
        public bool $list,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
        public array $constraints,
        public ?string $enum = null,
        public bool $intBacked = false,
        /** The parameter's doc comment, for documentation. */
        public string $description = '',
    ) {}
}
