<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests\Fixtures;

use Shirakaba\Database\Reader;

final readonly class UntypedService
{
    public function __construct(
        public Reader $db,
        public string $name,
    ) {}
}
