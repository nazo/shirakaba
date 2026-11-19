<?php

declare(strict_types=1);

namespace Shirakaba\Core\Scope;

use Closure;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Core\Internal\SafeObservation;
use Shirakaba\Core\Observe\Event;
use Throwable;

/**
 * State that belongs to one request (design/03-runtimes-and-scopes.md): values registered by type, cleanup
 * callbacks, cancellation and an optional deadline.
 *
 * The application creates it for every request and attaches it to the request as an
 * attribute; handlers can also declare a RequestScope parameter. It is not a container:
 * it only returns values that were registered.
 */
final class RequestScope
{
    /** @var array<class-string, object> */
    private array $values = [];

    /** @var list<Closure(): void> */
    private array $cleanups = [];

    private bool $closed = false;

    /**
     * @internal created by the application
     */
    public function __construct(
        private readonly Cancellation $cancellation,
        private readonly ?Deadline $deadline,
        private readonly SafeObservation $observation,
    ) {}

    /**
     * @throws LogicException when the request did not come through a Shirakaba application
     */
    public static function of(ServerRequestInterface $request): self
    {
        $scope = $request->getAttribute(self::class);
        if (!$scope instanceof self) {
            throw new LogicException('The request has no RequestScope; it was not handled by a Shirakaba application.');
        }

        return $scope;
    }

    /**
     * Registers a value under its class, or under $as (a supertype it should be found by).
     *
     * @template T of object
     * @param T $value
     * @param class-string<T>|null $as
     */
    public function set(object $value, ?string $as = null): void
    {
        $this->assertOpen();
        $this->values[$as ?? $value::class] = $value;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public function find(string $type): ?object
    {
        $value = $this->values[$type] ?? null;

        return $value instanceof $type ? $value : null;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     * @throws LogicException when nothing was registered under $type
     */
    public function get(string $type): object
    {
        return $this->find($type) ?? throw new LogicException(sprintf('No %s was registered in the request scope.', $type));
    }

    /**
     * Registers a callback that runs when the request scope closes, in reverse order of
     * registration. A failing callback does not stop the others.
     *
     * @param Closure(): void $cleanup
     */
    public function defer(Closure $cleanup): void
    {
        $this->assertOpen();
        $this->cleanups[] = $cleanup;
    }

    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    public function deadline(): ?Deadline
    {
        return $this->deadline;
    }

    /**
     * Reports an event to the application's observers, such as a database statement
     *. Observer failures never reach the caller (design/05-errors-and-observability.md).
     */
    public function record(Event $event): void
    {
        $this->observation->recorded($event);
    }

    /**
     * @internal
     */
    public function observation(): SafeObservation
    {
        return $this->observation;
    }

    /**
     * Runs the cleanup callbacks and reports their failures to the observer.
     *
     * @internal called by the application exactly once per request
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $cleanups = array_reverse($this->cleanups);
        $this->cleanups = [];
        $this->values = [];
        foreach ($cleanups as $cleanup) {
            try {
                $cleanup();
            } catch (Throwable $e) {
                $this->observation->cleanupFailed($e);
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('The request scope is closed.');
        }
    }
}
