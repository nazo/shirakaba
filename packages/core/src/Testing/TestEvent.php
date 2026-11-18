<?php

declare(strict_types=1);

namespace Shirakaba\Core\Testing;

use Shirakaba\Core\Observe\Event;

/**
 * A recorded event with fixed contents, for testing observers.
 */
final readonly class TestEvent implements Event
{
    /**
     * @param array<string, string|int|float|bool> $attributes
     */
    public function __construct(
        private string $name,
        private ?float $duration = null,
        private array $attributes = [],
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function duration(): ?float
    {
        return $this->duration;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
