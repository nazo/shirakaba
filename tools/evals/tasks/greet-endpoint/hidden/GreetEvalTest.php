<?php

declare(strict_types=1);

namespace App\Tests\Eval;

use App\Tests\TestApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GreetEvalTest extends TestCase
{
    public function testGreets(): void
    {
        $response = TestApp::create()->client->get('/greet?name=Ada');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['greeting' => 'Hello, Ada!'], json_decode((string) $response->getBody(), true));
    }

    public function testRejectsMissingEmptyAndLongNames(): void
    {
        $client = TestApp::create()->client;

        foreach (['/greet', '/greet?name=', '/greet?name=' . str_repeat('a', 51)] as $target) {
            $status = $client->get($target)->getStatusCode();
            self::assertTrue($status === 400 || $status === 422, $target . ' answered ' . $status);
        }
    }
}
