<?php

declare(strict_types=1);

use Psr\Http\Message\UploadedFileInterface;

final readonly class Upload
{
    public function __construct(
        public UploadedFileInterface $file,
    ) {}
}
