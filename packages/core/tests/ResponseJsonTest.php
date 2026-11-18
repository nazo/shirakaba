<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Testing\ResponseJson;
use Shirakaba\Http\Message\Response;
use UnexpectedValueException;

#[CoversClass(ResponseJson::class)]
final class ResponseJsonTest extends TestCase
{
    public function testReadsValuesByPointer(): void
    {
        $response = new Response(body: '{"items":[{"title":"a"}],"a/b":{"~":1}}');

        self::assertSame('a', ResponseJson::at($response, '/items/0/title'));
        self::assertSame(1, ResponseJson::at($response, '/a~1b/~0'));
        self::assertSame(['title' => 'a'], ResponseJson::at($response, '/items/0'));
        self::assertIsArray(ResponseJson::at($response, ''));
    }

    public function testMissingPointerFails(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ResponseJson::at(new Response(body: '{"a":1}'), '/a/b');
    }
}
