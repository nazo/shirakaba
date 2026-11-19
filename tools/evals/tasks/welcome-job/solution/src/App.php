<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\JsonResponse;
use Shirakaba\Orm\Entities;
use Shirakaba\Queue\Jobs;

final readonly class App
{
    public static function builder(Database $database, Jobs $jobs): AppBuilder
    {
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        $app->get('/health', static fn (): Text => new Text('ok'))->name('health');
        $app->post(
            '/users',
            #[JsonResponse(201, UserView::class)]
            static function (#[JsonBody] NewUser $input, #[Db] Writer $db) use ($jobs): Json {
                $user = $db->transaction(static function (Writer $db) use ($input, $jobs): User {
                    $user = Entities::insert(new User($input->name, $input->email), $db);
                    $jobs->push(new SendWelcome($user->id ?? 0), $db);

                    return $user;
                });

                return new Json(new UserView($user->id ?? 0, $user->name, $user->email));
            },
        )->name('users.create');

        return $app;
    }
}
