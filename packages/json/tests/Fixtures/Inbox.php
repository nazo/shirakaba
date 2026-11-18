<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Json\ListOf;

#[JsonDto]
final readonly class Inbox
{
    /**
     * @param list<Notification> $items
     */
    public function __construct(
        #[ListOf(Notification::class)] public array $items,
        public ?Notification $pinned = null,
    ) {}
}
