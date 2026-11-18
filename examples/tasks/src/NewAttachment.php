<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Form\FileTypes;
use Shirakaba\Form\MaxFileSize;
use Shirakaba\Validation\Length;
use Psr\Http\Message\UploadedFileInterface;

final readonly class NewAttachment
{
    public function __construct(
        #[MaxFileSize(5 * 1024 * 1024)] #[FileTypes(['image/png', 'image/jpeg', 'application/pdf'])]
        public UploadedFileInterface $file,
        #[Length(max: 200)]
        public string $note = '',
    ) {}
}
