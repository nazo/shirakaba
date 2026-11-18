<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\Text;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonResponse;
use Shirakaba\Queue\Jobs;
use Shirakaba\Validation\Length;

final readonly class App
{
    public static function builder(Database $database, Jobs $jobs): AppBuilder
    {
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        $app->get('/health', static fn (): Text => new Text('ok'))->name('health');
        $app->get(
            '/greet',
            #[JsonResponse(200, Greeting::class)]
            static fn (#[Query] #[Length(min: 1, max: 50)] string $name): Json => new Json(new Greeting(sprintf('Hello, %s!', $name))),
        )->name('greet');

        return $app;
    }
}
