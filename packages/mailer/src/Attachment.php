<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use InvalidArgumentException;

/**
 * A file attached to a mail. With a content ID it is an inline part that the HTML body refers
 * to as `cid:<contentId>`, such as a logo.
 */
final readonly class Attachment
{
    /**
     * @throws InvalidArgumentException for a filename, content type or content ID that could
     *                                   inject a header
     */
    public function __construct(
        public string $content,
        public string $filename,
        public string $contentType = 'application/octet-stream',
        public ?string $contentId = null,
    ) {
        if (preg_match('/[\r\n\0"\\\\]/', $filename) === 1 || $filename === '') {
            throw new InvalidArgumentException('An attachment needs a filename without line breaks, quotes or backslashes.');
        }
        if (preg_match('#\A[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+\z#', $contentType) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a content type.', $contentType));
        }
        if ($contentId !== null && preg_match('/\A[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.@-]+\z/', $contentId) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a content ID.', $contentId));
        }
    }

    /**
     * Reads a file now, so the attachment does not depend on the file later.
     *
     * @throws InvalidArgumentException when the file cannot be read
     */
    public static function fromFile(string $path, ?string $filename = null, string $contentType = 'application/octet-stream', ?string $contentId = null): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InvalidArgumentException(sprintf('Unable to read "%s".', $path));
        }

        return new self($content, $filename ?? basename($path), $contentType, $contentId);
    }
}
