<?php

declare(strict_types=1);

namespace Shirakaba\Session;

use Closure;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Core\Scope\RequestScope;

/**
 * The session of one request, kept in the RequestScope (never in globals, design/03-runtimes-and-scopes.md).
 * Values must be JSON-encodable.
 */
final class Session
{
    private const string FLASH = '_flash';

    /** @var array<string, mixed> */
    private array $flashed;

    private bool $modified = false;
    private bool $regenerated = false;
    private bool $invalidated = false;

    /** @var list<Closure(self): void> */
    private array $regenerateListeners = [];

    /**
     * @param array<string, mixed> $data
     * @internal created by SessionMiddleware
     */
    public function __construct(
        private string $id,
        private array $data,
        private readonly bool $new,
    ) {
        $flashed = $data[self::FLASH] ?? [];
        $this->flashed = is_array($flashed) ? self::stringKeys($flashed) : [];
        if (isset($this->data[self::FLASH])) {
            unset($this->data[self::FLASH]);
            $this->modified = true;
        }
    }

    /**
     * @throws LogicException when the request has no session (SessionMiddleware did not run)
     */
    public static function of(RequestScope|ServerRequestInterface $source): self
    {
        $scope = $source instanceof RequestScope ? $source : RequestScope::of($source);

        return $scope->get(self::class);
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/D', $id) === 1;
    }

    /**
     * @internal
     * @phpstan-impure nondet.random
     */
    public static function newId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertNotReserved($key);
        $this->data[$key] = $value;
        $this->modified = true;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->modified = true;
    }

    /**
     * Stores a value for the next request only.
     */
    public function flash(string $key, mixed $value): void
    {
        $next = $this->data[self::FLASH] ?? [];
        $next = is_array($next) ? $next : [];
        $next[$key] = $value;
        $this->data[self::FLASH] = $next;
        $this->modified = true;
    }

    /**
     * Reads a value flashed by the previous request.
     */
    public function flashed(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->flashed) ? $this->flashed[$key] : $default;
    }

    /**
     * Changes the session ID while keeping the data. Call after login to prevent session
     * fixation. Values registered with onRegenerate() (the CSRF token) are dropped.
     */
    public function regenerate(): void
    {
        $this->regenerated = true;
        $this->modified = true;
        foreach ($this->regenerateListeners as $listener) {
            $listener($this);
        }
    }

    /**
     * Runs $listener each time regenerate() is called during this request, so a package can
     * drop values tied to the old identity without Session knowing about them.
     *
     * @param Closure(self): void $listener
     */
    public function onRegenerate(Closure $listener): void
    {
        $this->regenerateListeners[] = $listener;
    }

    /**
     * Discards the data and the ID, for example on logout.
     */
    public function invalidate(): void
    {
        $this->data = [];
        $this->invalidated = true;
        $this->modified = true;
    }

    /**
     * @return array<string, mixed>
     * @internal
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @internal
     */
    public function isModified(): bool
    {
        return $this->modified;
    }

    /**
     * @internal
     */
    public function isNew(): bool
    {
        return $this->new;
    }

    /**
     * @internal
     */
    public function isRegenerated(): bool
    {
        return $this->regenerated;
    }

    /**
     * @internal
     */
    public function isInvalidated(): bool
    {
        return $this->invalidated;
    }

    /**
     * @internal
     */
    public function assignId(string $id): void
    {
        $this->id = $id;
    }

    private function assertNotReserved(string $key): void
    {
        if ($key === self::FLASH) {
            throw new LogicException(sprintf('The session key "%s" is reserved.', self::FLASH));
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeys(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
