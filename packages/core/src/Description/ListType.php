<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

final readonly class ListType implements Type
{
    public function __construct(
        public Type $items,
        public bool $nullable = false,
    ) {}

    public function isNullable(): bool
    {
        return $this->nullable;
    }
}
