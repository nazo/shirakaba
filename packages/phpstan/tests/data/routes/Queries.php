<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Fixtures;

use Shirakaba\Database\Reader;

final readonly class Queries
{
    public function __construct(
        private Reader $db,
    ) {}

    public function count(): mixed
    {
        return $this->db->fetchValue('SELECT 1');
    }
}
