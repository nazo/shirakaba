<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Closure;
use InvalidArgumentException;
use LogicException;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Writer;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonDto;
use ReflectionClass;

/**
 * Maps job classes to stable type names and handlers, and pushes jobs (design/10-background-work.md):
 *
 *     $jobs = new Jobs($queue);
 *     $jobs->handle(SendWelcomeMail::class, $mailer->sendWelcome(...), name: 'mail.welcome');
 *     $jobs->push(new SendWelcomeMail($user->id), $writer);
 *
 * Jobs are #[JsonDto] classes stored as JSON. The type name is stored with them, so name
 * jobs explicitly when their class may be renamed; a job of an unknown type is failed, never
 * run.
 */
final class Jobs
{
    /** @var array<class-string, array{string, Closure(object, RequestScope): void}> */
    private array $handlers = [];

    /** @var array<string, class-string> */
    private array $classes = [];

    public function __construct(
        public readonly Queue $queue,
    ) {}

    /**
     * @template T of object
     * @param class-string<T> $class a #[JsonDto] class
     * @param callable(T, RequestScope): void $handler receives the job and the scope it runs
     *                                                 in, for request-scoped services such as
     *                                                 `RequestDatabase::writer($database, $scope)`
     * @throws InvalidArgumentException when the class is not a #[JsonDto] or the name is taken
     */
    public function handle(string $class, callable $handler, ?string $name = null): self
    {
        if (!class_exists($class) || new ReflectionClass($class)->getAttributes(JsonDto::class) === []) {
            throw new InvalidArgumentException(sprintf('%s must be a #[JsonDto] class to be a job.', $class));
        }
        $name ??= $class;
        if (isset($this->classes[$name]) || isset($this->handlers[$class])) {
            throw new InvalidArgumentException(sprintf('The job %s is registered twice.', $name));
        }
        /** @var Closure(object, RequestScope): void $closure */
        $closure = Closure::fromCallable($handler);
        $this->handlers[$class] = [$name, $closure];
        $this->classes[$name] = $class;

        return $this;
    }

    /**
     * Queues a job in the caller's transaction (or after it commits, for queues outside the
     * database).
     *
     * @return bool false when a job with the same unique key is already waiting
     * @throws LogicException when the job's class is not registered
     * @throws \UnexpectedValueException when the job holds an object Json cannot encode
     */
    public function push(object $job, Writer $db, PushOptions $options = new PushOptions()): bool
    {
        [$name] = $this->handlers[$job::class] ?? throw new LogicException(sprintf('No handler is registered for %s.', $job::class));

        return $this->queue->push($name, Json::encode($job), $db, $options);
    }

    /**
     * @return class-string|null
     * @internal
     */
    public function classOf(string $type): ?string
    {
        return $this->classes[$type] ?? null;
    }

    /**
     * @return (Closure(object, RequestScope): void)|null
     * @internal
     */
    public function handlerOf(string $class): ?Closure
    {
        return ($this->handlers[$class] ?? null)[1] ?? null;
    }
}
