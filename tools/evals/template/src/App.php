<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\Queue\Jobs;

/**
 * Builds the HTTP application. Tests build it with a temporary SQLite database and an
 * in-memory queue.
 */
final readonly class App
{
    public static function builder(Database $database, Jobs $jobs): AppBuilder
    {
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        $app->get('/health', static fn (): Text => new Text('ok'))->name('health');

        return $app;
    }
}
