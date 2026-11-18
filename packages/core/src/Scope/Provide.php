<?php

declare(strict_types=1);

namespace Shirakaba\Core\Scope;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Makes application-scoped services available in every request's scope under the types they
 * are asked for (design/08-database.md), for classes built per request such as #[Db] repositories:
 *
 *     $app->middleware(new Provide([
 *         ClockInterface::class => $clock,
 *         Storage::class => $storage,
 *         Publisher::class => $pubsub,
 *     ]));
 *
 * Services are shared by all requests, so they must be safe for that (design/03-runtimes-and-scopes.md): immutable,
 * or holding only what every request may share.
 */
final readonly class Provide implements MiddlewareInterface
{
    /**
     * @param array<class-string, object> $services type => service
     * @throws InvalidArgumentException when a service is not an instance of its type
     */
    public function __construct(
        private array $services,
    ) {
        foreach ($services as $type => $service) {
            if (!$service instanceof $type) {
                throw new InvalidArgumentException(sprintf('Provide: the service for %s is a %s.', $type, $service::class));
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = RequestScope::of($request);
        foreach ($this->services as $type => $service) {
            $scope->set($service, $type);
        }

        return $handler->handle($request);
    }
}
