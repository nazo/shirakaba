<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Fixtures;

use Shirakaba\Database\Writer;

final readonly class Commands
{
    public function __construct(
        private Writer $db,
    ) {}

    public function touch(): int
    {
        return $this->db->execute('UPDATE t SET x = 1');
    }
}
