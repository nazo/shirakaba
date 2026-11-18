<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Internal;

use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\Databases;
use Shirakaba\DatabaseBridge\RequestDatabase;

/**
 * Hands a handler its database access. A plain serialisable object, so build plans can be cached (design/04-routing-and-handlers.md).
 *
 * @internal
 */
final readonly class DbResolver implements ArgumentResolver
{
    /**
     * @param class-string|null $class null for Reader or Writer itself
     * @param string $access the constructor parameter taking the Reader or Writer
     * @param list<array{string, class-string, bool}> $services parameter, type, has a default
     */
    public function __construct(
        private ?string $class,
        private bool $writer,
        private string $access = '',
        private array $services = [],
        private ?string $database = null,
        private bool $primary = false,
    ) {}

    /**
     * @throws InvalidApplication when a service without a default was not provided
     */
    public function resolve(Exchange $exchange): mixed
    {
        $databases = $exchange->scope->find(Databases::class);
        $database = $databases !== null ? $databases->get($this->database) : $exchange->scope->get(Database::class);
        $safe = in_array(strtoupper($exchange->request->getMethod()), ['GET', 'HEAD'], true);
        $access = match (true) {
            $this->writer => RequestDatabase::writer($database, $exchange->scope),
            $safe && !$this->primary => RequestDatabase::replicaReader($database, $exchange->scope),
            default => RequestDatabase::reader($database, $exchange->scope),
        };
        if ($this->class === null) {
            return $access;
        }
        $arguments = [$this->access => $access];
        foreach ($this->services as [$name, $type, $optional]) {
            $service = $exchange->scope->find($type);
            if ($service !== null) {
                $arguments[$name] = $service;
            } elseif (!$optional) {
                throw new InvalidApplication(sprintf('%s needs a %s for $%s; provide it with the Provide middleware.', $this->class, $type, $name));
            }
        }

        return new ($this->class)(...$arguments);
    }
}
