<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\RouteGroup;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;

/**
 * Assembles the application in plain PHP (design/01-principles.md).
 */
final readonly class TaskApi
{
    public static function builder(Database $database): AppBuilder
    {
        $handlers = new TaskHandlers();

        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        $app->group('/tasks', static function (RouteGroup $routes) use ($handlers): void {
            $routes->get('', $handlers->list(...))->name('tasks.list');
            $routes->post('', $handlers->create(...))->name('tasks.create');
            $routes->post('/complete-all', $handlers->completeAll(...))->name('tasks.completeAll');
            $routes->get('/{id}', $handlers->show(...))->name('tasks.show');
            $routes->put('/{id}', $handlers->replace(...))->name('tasks.replace');
            $routes->patch('/{id}', $handlers->update(...))->name('tasks.update');
            $routes->post('/{id}/complete', $handlers->complete(...))->name('tasks.complete');
            $routes->delete('/{id}', $handlers->delete(...))->name('tasks.delete');
            $routes->post('/{id}/attachments', $handlers->attach(...))->name('tasks.attach');
        });

        return $app;
    }
}
