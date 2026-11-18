<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Json\MapOf;

#[JsonDto]
final readonly class Reactions
{
    /**
     * @param array<array-key, int> $counts
     * @param array<array-key, Address>|null $places
     */
    public function __construct(
        #[MapOf('int')] public array $counts,
        #[MapOf(Address::class)] public ?array $places = null,
    ) {}
}
