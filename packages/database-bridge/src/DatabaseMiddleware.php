<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\TranslatesFailures;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Database;
use Shirakaba\Orm\EntityNotFound;
use Shirakaba\Orm\StaleEntity;
use Throwable;

/**
 * Makes the databases available to #[Db] parameters: the default one, and others by name for
 * `#[Db('analytics')]`. No connection is opened until a handler asks for one.
 *
 *     $app->middleware(new DatabaseMiddleware($main, ['analytics' => $analytics]));
 *
 * Added as global middleware, it also translates the ORM's errors for HTTP clients (design/08-database.md):
 * EntityNotFound becomes a 404 and StaleEntity a 409, with the ORM's message as the detail.
 */
final readonly class DatabaseMiddleware implements MiddlewareInterface, TranslatesFailures
{
    private Databases $databases;

    /**
     * @param array<string, Database> $named
     */
    public function __construct(
        private Database $database,
        array $named = [],
    ) {
        $this->databases = new Databases($database, $named);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = RequestScope::of($request);
        $scope->set($this->database);
        $scope->set($this->databases);

        return $handler->handle($request);
    }

    /**
     * EntityNotFound is a 404 and StaleEntity a 409; their messages name entities only by
     * their #[Table] label, so they are safe to show.
     */
    public function translate(Throwable $error): ?HttpFailure
    {
        return match (true) {
            $error instanceof EntityNotFound => new HttpFailure(404, $error->getMessage(), previous: $error),
            $error instanceof StaleEntity => new HttpFailure(409, $error->getMessage(), previous: $error),
            default => null,
        };
    }
}
