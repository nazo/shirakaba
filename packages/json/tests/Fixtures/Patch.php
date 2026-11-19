<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\Optional;
use Shirakaba\Json\OptionalOf;
use Shirakaba\Validation\Range;

final readonly class Patch
{
    /**
     * @param Optional<string|null> $nickname
     * @param Optional<int> $age
     * @param Optional<Role> $role
     */
    public function __construct(
        #[OptionalOf('string', nullable: true)] public Optional $nickname = new Optional(),
        #[OptionalOf('int')]
        #[Range(min: 0)] public Optional $age = new Optional(),
        #[OptionalOf(Role::class)] public Optional $role = new Optional(),
    ) {}
}
