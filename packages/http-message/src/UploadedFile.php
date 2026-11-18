<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * PSR-7 uploaded file, backed either by a file path or by a stream.
 *
 * A file path that PHP's SAPI registered as an upload is moved with
 * move_uploaded_file(). Any other path is moved with rename(), which lets runtimes
 * that receive uploads outside the SAPI (RoadRunner, Swoole) hand over their
 * temporary files. Paths therefore must come from the runtime, never from user input.
 *
 * The uploaded file owns the stream it is given: a successful move closes it.
 * A stream is copied to a temporary file next to the target and renamed into place,
 * so a failed copy never leaves a truncated target behind.
 */
final class UploadedFile implements UploadedFileInterface
{
    /**
     * @var list<int>
     */
    private const array ERRORS = [
        UPLOAD_ERR_OK,
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE,
        UPLOAD_ERR_PARTIAL,
        UPLOAD_ERR_NO_FILE,
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE,
        UPLOAD_ERR_EXTENSION,
    ];

    private const int CHUNK_SIZE = 1 << 16;

    private bool $moved = false;

    /** Set when a failed move consumed part of a stream that cannot be rewound. */
    private bool $consumed = false;

    /**
     * @param StreamInterface|non-empty-string $streamOrFile
     * @throws InvalidArgumentException when $error is not an UPLOAD_ERR_* constant
     */
    public function __construct(
        private readonly StreamInterface|string $streamOrFile,
        private readonly ?int $size,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        if (!in_array($error, self::ERRORS, true)) {
            throw new InvalidArgumentException(sprintf('Invalid upload error code %d.', $error));
        }
    }

    public function getStream(): StreamInterface
    {
        $this->assertAvailable();

        return $this->streamOrFile instanceof StreamInterface
            ? $this->streamOrFile
            : Stream::fromFile($this->streamOrFile, 'r');
    }

    /**
     * @phpstan-impure io, nondet.random (a temporary file with a random name)
     */
    public function moveTo(string $targetPath): void
    {
        $this->assertAvailable();
        if ($targetPath === '') {
            throw new InvalidArgumentException('The target path must not be empty.');
        }

        if ($this->streamOrFile instanceof StreamInterface) {
            try {
                self::copyStream($this->streamOrFile, $targetPath);
            } catch (Throwable $e) {
                // A retry would silently store only the unread remainder.
                $this->consumed = !$this->streamOrFile->isSeekable();

                throw $e;
            }
            $this->streamOrFile->close();
        } else {
            self::moveFile($this->streamOrFile, $targetPath);
        }

        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    private function assertAvailable(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf('The file was not uploaded successfully (error %d).', $this->error));
        }
        if ($this->moved) {
            throw new RuntimeException('The uploaded file has already been moved.');
        }
        if ($this->consumed) {
            throw new RuntimeException('A failed move consumed part of the non-seekable upload stream; it cannot be read again.');
        }
    }

    private static function moveFile(string $source, string $targetPath): void
    {
        $moved = is_uploaded_file($source)
            ? @move_uploaded_file($source, $targetPath)
            : @rename($source, $targetPath);
        if (!$moved) {
            throw new RuntimeException(sprintf('Unable to move the uploaded file to "%s".', $targetPath));
        }
    }

    private static function copyStream(StreamInterface $source, string $targetPath): void
    {
        $temporaryPath = sprintf('%s.%s.part', $targetPath, bin2hex(random_bytes(8)));
        // Mode "x" refuses to follow a file planted at the temporary path.
        $target = Stream::fromFile($temporaryPath, 'x');

        try {
            if ($source->isSeekable()) {
                $source->rewind();
            }
            while (true) {
                $chunk = $source->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    if ($source->eof()) {
                        break;
                    }
                    throw new RuntimeException('The upload stream returned no data before its end; refusing to store a partial file.');
                }
                self::writeAll($target, $chunk);
            }
            $target->close();

            if (!@rename($temporaryPath, $targetPath)) {
                throw new RuntimeException(sprintf('Unable to move the uploaded file to "%s".', $targetPath));
            }
        } catch (Throwable $e) {
            $target->close();
            @unlink($temporaryPath);

            throw $e;
        }
    }

    private static function writeAll(StreamInterface $target, string $data): void
    {
        while ($data !== '') {
            $written = $target->write($data);
            if ($written <= 0) {
                throw new RuntimeException('Unable to write the uploaded file; the target accepted no data.');
            }
            $data = substr($data, $written);
        }
    }
}
