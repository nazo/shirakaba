<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Json\MapOf;

#[JsonDto]
final readonly class Tally
{
    /**
     * @param array<array-key, int> $reactions
     * @param array<array-key, Followed> $users
     */
    public function __construct(
        #[MapOf('int')] public array $reactions,
        #[MapOf(Followed::class)] public array $users,
    ) {}
}
