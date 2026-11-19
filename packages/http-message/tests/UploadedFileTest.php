<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Http\Message\Tests\Fixtures\LimitedWriteWrapper;
use Shirakaba\Http\Message\Tests\Fixtures\ScriptedReadStream;
use Shirakaba\Http\Message\UploadedFile;

#[CoversClass(UploadedFile::class)]
final class UploadedFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shirakaba-upload-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        LimitedWriteWrapper::unregister();
    }

    public function testEmptyReadBeforeEndFailsWithoutCreatingTarget(): void
    {
        $file = new UploadedFile(new ScriptedReadStream(['ab', '', 'cd']), 4);

        try {
            $file->moveTo($this->dir . '/a.txt');
            self::fail('An empty read before the end must not count as a complete copy.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no data before its end', $e->getMessage());
        }

        self::assertSame([], $this->filesInDir());
    }

    public function testReadFailureMidwayLeavesNoPartialFileAndAllowsRetry(): void
    {
        $file = new UploadedFile(new ScriptedReadStream(['ab', new RuntimeException('connection reset')]), 4);

        try {
            $file->moveTo($this->dir . '/a.txt');
            self::fail('A read failure must abort the move.');
        } catch (RuntimeException $e) {
            self::assertSame('connection reset', $e->getMessage());
        }

        self::assertSame([], $this->filesInDir());
        // Retrying would store only the unread remainder of the non-seekable stream.
        $this->expectExceptionMessage('cannot be read again');
        $file->moveTo($this->dir . '/a.txt');
    }

    public function testSeekableStreamCanBeMovedAgainAfterFailure(): void
    {
        $file = new UploadedFile(Stream::fromString('content'), 7);

        try {
            $file->moveTo($this->dir . '/missing/a.txt');
            self::fail('Moving into a missing directory must fail.');
        } catch (RuntimeException) {
        }

        $file->moveTo($this->dir . '/a.txt');
        self::assertSame('content', file_get_contents($this->dir . '/a.txt'));
    }

    public function testShortWritesAreCompleted(): void
    {
        LimitedWriteWrapper::register(PHP_INT_MAX);
        $target = LimitedWriteWrapper::SCHEME . '://a.txt';

        new UploadedFile(new ScriptedReadStream(['abc', 'def']), 6)->moveTo($target);

        self::assertSame([$target => 'abcdef'], LimitedWriteWrapper::$files);
    }

    public function testTargetThatStopsAcceptingDataFailsAndIsCleanedUp(): void
    {
        LimitedWriteWrapper::register(2);
        $target = LimitedWriteWrapper::SCHEME . '://a.txt';

        try {
            new UploadedFile(new ScriptedReadStream(['abcdef']), 6)->moveTo($target);
            self::fail('A target that accepts no more data must fail the move.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('accepted no data', $e->getMessage());
        }

        self::assertSame([], LimitedWriteWrapper::$files);
    }

    public function testSuccessfulMoveClosesSourceStream(): void
    {
        $source = new ScriptedReadStream(['content']);

        new UploadedFile($source, 7)->moveTo($this->dir . '/a.txt');

        self::assertTrue($source->closed);
        self::assertSame(['a.txt'], $this->filesInDir());
    }

    public function testSuccessfulMoveDetachesStreamCreatedFromString(): void
    {
        $source = Stream::fromString('content');

        new UploadedFile($source, 7)->moveTo($this->dir . '/a.txt');

        self::assertFalse($source->isReadable());
        self::assertNull($source->detach());
    }

    public function testMoveOverwritesExistingTarget(): void
    {
        file_put_contents($this->dir . '/a.txt', 'old');

        new UploadedFile(Stream::fromString('new'), 3)->moveTo($this->dir . '/a.txt');

        self::assertSame('new', file_get_contents($this->dir . '/a.txt'));
    }

    /**
     * @return list<string>
     */
    private function filesInDir(): array
    {
        $entries = scandir($this->dir);

        return array_values(array_diff($entries === false ? [] : $entries, ['.', '..']));
    }

    public function testRejectsUnknownErrorCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UploadedFile(Stream::fromString(''), 0, 5);
    }

    public function testFailedUploadHasNoStream(): void
    {
        $file = new UploadedFile(Stream::fromString(''), null, UPLOAD_ERR_PARTIAL);

        self::assertSame(UPLOAD_ERR_PARTIAL, $file->getError());
        $this->expectException(RuntimeException::class);
        $file->getStream();
    }

    public function testFailedUploadCannotBeMoved(): void
    {
        $this->expectException(RuntimeException::class);
        new UploadedFile(Stream::fromString(''), null, UPLOAD_ERR_NO_FILE)->moveTo($this->dir . '/target');
    }

    public function testMovesRuntimeProvidedTemporaryFile(): void
    {
        $source = $this->dir . '/upload.tmp';
        file_put_contents($source, 'content');
        $file = new UploadedFile($source, 7, UPLOAD_ERR_OK, 'a.txt', 'text/plain');

        self::assertSame('content', (string) $file->getStream());

        $file->moveTo($this->dir . '/a.txt');

        self::assertFileDoesNotExist($source);
        self::assertSame('content', file_get_contents($this->dir . '/a.txt'));
    }

    public function testMoveToUnwritableLocationThrowsInsteadOfWarning(): void
    {
        $source = $this->dir . '/upload.tmp';
        file_put_contents($source, 'content');

        $this->expectException(RuntimeException::class);
        new UploadedFile($source, 7)->moveTo($this->dir . '/missing/a.txt');
    }

    public function testRejectsEmptyTargetPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UploadedFile(Stream::fromString('x'), 1)->moveTo('');
    }

    public function testCopiesStreamFromStart(): void
    {
        $stream = Stream::fromString('content');
        $stream->seek(3);

        new UploadedFile($stream, 7)->moveTo($this->dir . '/a.txt');

        self::assertSame('content', file_get_contents($this->dir . '/a.txt'));
    }
}
