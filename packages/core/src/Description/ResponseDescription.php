<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

final readonly class ResponseDescription
{
    public function __construct(
        public int $status,
        /** Empty when the response has no body. */
        public string $contentType = '',
        public ?Type $body = null,
        public string $description = '',
    ) {}
}
