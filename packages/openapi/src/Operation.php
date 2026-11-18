<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi;

/**
 * Documentation that code cannot express, attached to a route by its name (design/06-json-and-openapi.md).
 */
final readonly class Operation
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $summary = '',
        public string $description = '',
        public array $tags = [],
        public bool $deprecated = false,
    ) {}
}
