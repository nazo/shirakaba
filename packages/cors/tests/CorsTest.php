<?php

declare(strict_types=1);

namespace Shirakaba\Cors\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Cors\Cors;

#[CoversClass(Cors::class)]
final class CorsTest extends TestCase
{
    private static function client(Cors $cors): TestClient
    {
        $app = new AppBuilder();
        $app->middleware($cors);
        $app->get('/items', static fn (): Text => new Text('items'));
        $app->get('/boom', static fn (): Text => throw new LogicException('boom'));

        return new TestClient($app->build());
    }

    private static function cors(): Cors
    {
        return new Cors(
            ['https://app.example.com'],
            allowedHeaders: ['Content-Type', 'Authorization'],
            exposedHeaders: ['X-Total'],
            allowCredentials: true,
            maxAge: 600,
        );
    }

    public function testSimpleRequestFromAllowedOrigin(): void
    {
        $response = self::client(self::cors())->get('/items', ['Origin' => 'https://app.example.com']);

        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('X-Total', $response->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testDisallowedOriginGetsNoAllowHeaders(): void
    {
        $response = self::client(self::cors())->get('/items', ['Origin' => 'https://evil.example']);

        self::assertSame('items', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testRequestsWithoutOriginAreUntouched(): void
    {
        $response = self::client(self::cors())->get('/items');

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    public function testPreflight(): void
    {
        $response = self::client(self::cors())->request('OPTIONS', '/items', [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'PUT',
            'Access-Control-Request-Headers' => 'content-type, authorization',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, HEAD, POST, PUT, PATCH, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testPreflightWithUnlistedHeaderOrMethodIsNotAllowed(): void
    {
        $client = self::client(self::cors());
        $badHeader = $client->request('OPTIONS', '/items', [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'X-Secret',
        ]);
        $badMethod = $client->request('OPTIONS', '/items', [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'TRACE',
        ]);

        self::assertFalse($badHeader->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($badMethod->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testErrorResponsesCarryCorsHeaders(): void
    {
        $client = self::client(self::cors());

        self::assertSame(
            'https://app.example.com',
            $client->get('/boom', ['Origin' => 'https://app.example.com'])->getHeaderLine('Access-Control-Allow-Origin'),
        );
        self::assertSame(
            'https://app.example.com',
            $client->get('/missing', ['Origin' => 'https://app.example.com'])->getHeaderLine('Access-Control-Allow-Origin'),
        );
    }

    public function testAnyOrigin(): void
    {
        $response = self::client(new Cors(['*']))->get('/items', ['Origin' => 'https://anywhere.example']);

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    public function testRejectsAnyOriginWithCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Cors(['*'], allowCredentials: true);
    }

    public function testRejectsMalformedOrigins(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Cors(['https://app.example.com/']);
    }
}
