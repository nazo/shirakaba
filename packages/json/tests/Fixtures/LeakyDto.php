<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final class LeakyDto
{
    public function __construct(
        public string $name,
        string $secret,
    ) {
        $this->name .= strlen($secret) > 0 ? '' : '!';
    }
}
