<?php

declare(strict_types=1);

namespace Shirakaba\Query\Tests\Fixtures;

enum Status: string
{
    case Open = 'open';
    case Done = 'done';
}
