<?php

declare(strict_types=1);

namespace Shirakaba\Template\Tests\Fixtures;

use Shirakaba\Template\Html;

final readonly class Welcome
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        public User $user,
        public array $items,
        public string $site,
        public Html $signature,
    ) {}
}
