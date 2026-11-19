<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Shirakaba\Http\Message\Response;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function testUsesRegisteredReasonPhraseByDefault(): void
    {
        self::assertSame('OK', new Response()->getReasonPhrase());
        self::assertSame('Unprocessable Content', new Response(422)->getReasonPhrase());
        self::assertSame(
            'Not Found',
            new Response()
                ->withStatus(404)
                ->getReasonPhrase(),
        );
    }

    public function testUnregisteredStatusHasEmptyReasonPhrase(): void
    {
        self::assertSame('', new Response(299)->getReasonPhrase());
    }

    public function testKeepsCustomReasonPhrase(): void
    {
        self::assertSame(
            'Fine',
            new Response()
                ->withStatus(200, 'Fine')
                ->getReasonPhrase(),
        );
    }

    #[TestWith([99])]
    #[TestWith([600])]
    public function testRejectsOutOfRangeStatus(int $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Response($code);
    }

    public function testRejectsReasonPhraseWithLineBreak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new Response()->withStatus(200, "OK\r\nX-Injected: 1");
    }
}
