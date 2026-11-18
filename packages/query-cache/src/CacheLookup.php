<?php

declare(strict_types=1);

namespace Shirakaba\QueryCache;

use Shirakaba\Database\Observe\DatabaseEvent;

/**
 * Reported to observers for every cached read: "db.cache.hit" or "db.cache.miss".
 */
final readonly class CacheLookup implements DatabaseEvent
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public bool $hit,
        public array $tags,
    ) {}

    public function name(): string
    {
        return $this->hit ? 'db.cache.hit' : 'db.cache.miss';
    }

    public function duration(): ?float
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['shirakaba.cache.tags' => implode(',', $this->tags)];
    }
}
