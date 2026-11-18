<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Http\ProblemCode;

use BackedEnum;
use Shirakaba\Core\Description\EnumType;
use Shirakaba\Core\Description\Type;

/**
 * @internal
 */
final readonly class EnumNode implements Node
{
    /**
     * @param class-string<BackedEnum> $class
     */
    public function __construct(
        private string $class,
        private bool $intBacked,
        private bool $nullable,
    ) {}

    public function map(mixed $value, string $pointer, Problems $problems): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }

        $class = $this->class;
        $case = match (true) {
            $this->intBacked && is_int($value) => $class::tryFrom($value),
            !$this->intBacked && is_string($value) => $class::tryFrom($value),
            default => null,
        };
        if ($case === null) {
            $values = array_map(static fn (BackedEnum $case): string => json_encode($case->value, JSON_THROW_ON_ERROR), $class::cases());
            $problems->mapping($pointer, 'must be one of: ' . implode(', ', $values) . ($this->nullable ? ' or null' : ''), ProblemCode::INVALID_CHOICE);
        }

        return $case;
    }

    public function type(): Type
    {
        return EnumType::of($this->class, $this->nullable);
    }
}
