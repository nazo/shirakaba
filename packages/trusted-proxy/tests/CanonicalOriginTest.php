<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\TrustedProxy\CanonicalOrigin;

#[CoversClass(CanonicalOrigin::class)]
final class CanonicalOriginTest extends TestCase
{
    public function testBuildsAbsoluteUrls(): void
    {
        $origin = new CanonicalOrigin('https://App.Example.com:8443/');

        self::assertSame('https://app.example.com:8443', (string) $origin);
        self::assertSame('https://app.example.com:8443/password-reset?token=a%2Fb%20c&next=%2Fhome', $origin->url('/password-reset', [
            'token' => 'a/b c',
            'next' => '/home',
        ]));
        self::assertSame('https://app.example.com:8443/files/a%20b', $origin->url('/files/a b'));
        // A path that looks protocol-relative stays on the origin.
        self::assertSame('https://app.example.com:8443//evil.example/x', $origin->url('//evil.example/x'));
    }

    public function testDefaultPortsAreDropped(): void
    {
        self::assertSame('https://app.example.com/', new CanonicalOrigin('https://app.example.com:443')->url('/'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOrigins(): iterable
    {
        yield 'no scheme' => ['app.example.com'];
        yield 'other scheme' => ['ftp://app.example.com'];
        yield 'path' => ['https://app.example.com/app'];
        yield 'query' => ['https://app.example.com?x=1'];
        yield 'empty query' => ['https://app.example.com/?'];
        yield 'fragment' => ['https://app.example.com#top'];
        yield 'user info' => ['https://user@app.example.com'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidOrigins')]
    public function testRejectsInvalidOrigins(string $origin): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CanonicalOrigin($origin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'relative' => ['users'];
        yield 'absolute URL' => ['https://evil.example/'];
        yield 'with query' => ['/a?b=c'];
        yield 'with fragment' => ['/a#b'];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPaths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CanonicalOrigin('https://app.example.com')->url($path);
    }
}
