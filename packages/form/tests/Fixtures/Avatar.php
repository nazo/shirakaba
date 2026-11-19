<?php

declare(strict_types=1);

namespace Shirakaba\Form\Tests\Fixtures;

use Psr\Http\Message\UploadedFileInterface;
use Shirakaba\Form\FileTypes;
use Shirakaba\Form\MaxFileSize;

final readonly class Avatar
{
    public function __construct(
        public string $caption,
        #[MaxFileSize(1024)]
        #[FileTypes(['image/png'])] public UploadedFileInterface $image,
    ) {}
}
