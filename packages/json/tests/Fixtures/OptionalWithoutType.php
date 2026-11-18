<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\Optional;

final readonly class OptionalWithoutType
{
    /**
     * @param Optional<mixed> $value
     */
    public function __construct(
        public Optional $value = new Optional(),
    ) {}
}
