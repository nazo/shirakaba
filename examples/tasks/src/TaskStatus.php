<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

enum TaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
}
