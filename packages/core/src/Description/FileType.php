<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * An uploaded file in a multipart body.
 */
final readonly class FileType implements Type
{
    /**
     * @param list<string> $mediaTypes accepted media types; empty for any
     */
    public function __construct(
        public array $mediaTypes = [],
        public bool $nullable = false,
    ) {}

    public function isNullable(): bool
    {
        return $this->nullable;
    }
}
