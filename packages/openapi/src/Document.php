<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi;

use JsonException;

/**
 * A generated OpenAPI document and the warnings produced while building it.
 */
final readonly class Document
{
    /**
     * @param array<string, mixed> $content
     * @param list<string> $warnings things left out because they could not be described
     */
    public function __construct(
        public array $content,
        public array $warnings,
    ) {}

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return (
            json_encode(
                $this->content,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            ) . "\n"
        );
    }
}
