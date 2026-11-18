<?php

declare(strict_types=1);

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\Database\Writer;
use Shirakaba\PHPStan\Tests\Fixtures\Handlers;
use Shirakaba\PHPStan\Tests\Fixtures\Invokable;

$handlers = new Handlers();
$app = new AppBuilder();
$app->get('/ok', $handlers->show(...));
$app->get('/closure', static fn (#[Db] Writer $db): Text => new Text('closure'));
$app->get('/method', $handlers->touch(...));
$app->head('/invokable', new Invokable());
$app->post('/write', $handlers->touch(...));
$app->get('/queries', static fn (#[Db] \Shirakaba\PHPStan\Tests\Fixtures\Queries $tasks): Text => new Text('queries'));
$app->get('/commands', static fn (#[Db] \Shirakaba\PHPStan\Tests\Fixtures\Commands $tasks): Text => new Text('commands'));
$app->group('/api/todos', static function (\Shirakaba\Core\RouteGroup $routes) use ($handlers): void {
    $routes->get('', $handlers->touch(...));
    $routes->get('/{id}', $handlers->touch(...));
});
