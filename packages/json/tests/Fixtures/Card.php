<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Json\JsonName;
use Shirakaba\Json\ListOf;

#[JsonDto]
final readonly class Card
{
    /**
     * @param list<Address> $addresses
     */
    public function __construct(
        #[JsonName('display_name')] public string $name,
        public Role $role,
        #[ListOf(Address::class)] public array $addresses = [],
    ) {}
}
