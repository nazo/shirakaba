<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

final readonly class UntypedList
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(
        public array $items,
    ) {}
}
