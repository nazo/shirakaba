<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Database\Database;
use Shirakaba\Queue\Jobs;

/**
 * Registers the application's job handlers.
 */
final readonly class Background
{
    public static function register(Jobs $jobs, Database $database): void
    {
    }
}
