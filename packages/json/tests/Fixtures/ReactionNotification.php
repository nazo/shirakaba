<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Validation\Length;

#[JsonDto]
final readonly class ReactionNotification implements Notification
{
    public function __construct(
        public string $id,
        public string $note,
        #[Length(min: 1, max: 8)] public string $reaction,
    ) {}
}
