<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use LogicException;

/**
 * A DTO field that may be absent from the request, as distinct from null (design/06-json-and-openapi.md):
 *
 *     #[OptionalOf('string')] public Optional $title = new Optional(),
 *
 * `new Optional()` is the absent value; the mapper creates present ones.
 *
 * @template-covariant T
 */
final readonly class Optional
{
    /**
     * Call without arguments for the absent value; use of() for a present one.
     * (A clone-with in of() would be neater, but PHPStan does not yet follow it.)
     *
     * @param T|null $value
     */
    public function __construct(
        private bool $present = false,
        private mixed $value = null,
    ) {}

    /**
     * @template V
     * @param V $value
     * @return self<V>
     */
    public static function of(mixed $value): self
    {
        return new self(true, $value);
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    /**
     * @return T
     * @throws LogicException when the field was absent
     */
    public function get(): mixed
    {
        if (!$this->present) {
            throw new LogicException('The field was not sent.');
        }

        /** @var T */
        return $this->value;
    }

    /**
     * @template D
     * @param D $default
     * @return T|D
     */
    public function or(mixed $default): mixed
    {
        return $this->present ? $this->get() : $default;
    }
}
