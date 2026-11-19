<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests\Conformance;

use Http\Psr7Test\UploadedFileIntegrationTest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\UploadedFileInterface;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Http\Message\UploadedFile;

#[CoversClass(UploadedFile::class)]
final class UploadedFileConformanceTest extends UploadedFileIntegrationTest
{
    public function createSubject(): UploadedFileInterface
    {
        return new UploadedFile(Stream::fromString('Writing to a temporary file.'), 28, UPLOAD_ERR_OK, 'file.txt', 'text/plain');
    }
}
