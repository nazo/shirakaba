<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * Exactly one string value, such as the discriminator of a union member ("type": "follow").
 */
final readonly class ConstType implements Type
{
    public function __construct(
        public string $value,
    ) {}

    public function isNullable(): bool
    {
        return false;
    }
}
