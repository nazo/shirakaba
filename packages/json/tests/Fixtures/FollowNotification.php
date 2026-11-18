<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Json\JsonName;

#[JsonDto]
final readonly class FollowNotification implements Notification
{
    public function __construct(
        public string $id,
        #[JsonName('userId')] public string $user,
    ) {}
}
