<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi;

/**
 * The document's "info" object.
 */
final readonly class Info
{
    public function __construct(
        public string $title,
        public string $version,
        public string $description = '',
    ) {}
}
