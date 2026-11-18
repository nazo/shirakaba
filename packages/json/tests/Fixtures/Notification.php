<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonUnion;

#[JsonUnion('type', [
    'follow' => FollowNotification::class,
    'reaction' => ReactionNotification::class,
])]
interface Notification {}
