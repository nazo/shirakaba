<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

use Shirakaba\Json\JsonUnion;

#[JsonUnion('kind', ['follow' => Followed::class, 'reaction' => Reacted::class], name: 'ActivityItem')]
interface Activity {}
