<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

final readonly class ScalarType implements Type
{
    /**
     * @param 'string'|'int'|'float'|'bool' $name
     * @param 'date-time'|'date'|null $format a well-known string format (design/07-date-and-time.md)
     */
    public function __construct(
        public string $name,
        public bool $nullable = false,
        public ?string $format = null,
    ) {}

    public function isNullable(): bool
    {
        return $this->nullable;
    }
}
