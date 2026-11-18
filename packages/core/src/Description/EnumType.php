<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

use BackedEnum;

final readonly class EnumType implements Type
{
    /**
     * @param class-string<BackedEnum> $class
     * @param list<int|string> $values the backing values
     */
    public function __construct(
        public string $class,
        public array $values,
        public bool $nullable = false,
    ) {}

    /**
     * @param class-string<BackedEnum> $class
     */
    public static function of(string $class, bool $nullable = false): self
    {
        return new self($class, array_map(static fn (BackedEnum $case): int|string => $case->value, $class::cases()), $nullable);
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }
}
