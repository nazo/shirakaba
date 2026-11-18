<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * An object with arbitrary string keys whose values share one type, such as `{"👍": 2}`.
 */
final readonly class MapType implements Type
{
    public function __construct(
        public Type $values,
        public bool $nullable = false,
    ) {}

    public function isNullable(): bool
    {
        return $this->nullable;
    }
}
