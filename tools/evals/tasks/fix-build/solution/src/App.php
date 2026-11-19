<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\Text;
use Shirakaba\Database\Database;
use Shirakaba\Database\Reader;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\Json\Json;
use Shirakaba\Orm\Entities;
use Shirakaba\Queue\Jobs;

final readonly class App
{
    public static function builder(Database $database, Jobs $jobs): AppBuilder
    {
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        $app->get('/health', static fn (): Text => new Text('ok'))->name('health');
        $app->get('/users/{id}', static function (#[Path] int $id, #[Db] Reader $db): Json {
            $user = Entities::get(User::class, $id, $db);

            return new Json(['id' => $user->id, 'name' => $user->name]);
        })->name('users.show');

        return $app;
    }
}
