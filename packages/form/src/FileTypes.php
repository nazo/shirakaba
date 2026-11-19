<?php

declare(strict_types=1);

namespace Shirakaba\Form;

use Attribute;
use finfo;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Restricts an uploaded file to media types detected from its content. The media type the
 * client declares is never trusted.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FileTypes implements Constraint
{
    /**
     * @param non-empty-list<string> $mediaTypes such as ["image/png", "image/jpeg"]
     * @throws InvalidArgumentException when no media type is given
     */
    public function __construct(
        public array $mediaTypes,
    ) {
        if ($mediaTypes === []) {
            throw new InvalidArgumentException('FileTypes needs at least one media type.');
        }
    }

    public function violation(mixed $value): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return null;
        }
        $stream = $value->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        // The first kilobytes are enough for content sniffing.
        $head = $stream->read(8192);
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $detected = new finfo(FILEINFO_MIME_TYPE)->buffer($head);

        return is_string($detected) && in_array($detected, $this->mediaTypes, true)
            ? null
            : 'must be one of the file types: ' . implode(', ', $this->mediaTypes);
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return count($this->mediaTypes) === 1 ? ['contentMediaType' => $this->mediaTypes[0]] : [];
    }
}
