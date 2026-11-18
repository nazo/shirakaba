<?php

declare(strict_types=1);

namespace Shirakaba\Form;

use Attribute;
use InvalidArgumentException;
use Shirakaba\Core\Extraction\Constraint;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Limits the size of an uploaded file, measured on the received data.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class MaxFileSize implements Constraint
{
    /**
     * @throws InvalidArgumentException when the limit is not positive
     */
    public function __construct(
        public int $bytes,
    ) {
        if ($bytes < 1) {
            throw new InvalidArgumentException('The maximum file size must be positive.');
        }
    }

    public function violation(mixed $value): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return null;
        }
        $size = $value->getSize() ?? $value->getStream()->getSize();

        return $size !== null && $size > $this->bytes ? sprintf('must be at most %d bytes', $this->bytes) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return [];
    }
}
