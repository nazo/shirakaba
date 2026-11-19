<?php

declare(strict_types=1);

namespace Shirakaba\Sapi\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Sapi\ServerRequestBuilder;

#[CoversClass(ServerRequestBuilder::class)]
final class ServerRequestBuilderTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files
     */
    private static function build(array $server, array $post = [], array $files = [], string $body = ''): ServerRequestInterface
    {
        return ServerRequestBuilder::build($server, ['session' => 'abc'], ['page' => '2'], $post, $files, Stream::fromString($body));
    }

    public function testBuildsRequestFromServerValues(): void
    {
        $request = self::build([
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/users/42?page=2',
            'SERVER_PROTOCOL' => 'HTTP/2.0',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com:8443',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUEST_ID' => 'r1',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '2',
        ], body: '{}');

        self::assertSame('PUT', $request->getMethod());
        self::assertSame('https://example.com:8443/users/42?page=2', (string) $request->getUri());
        self::assertSame('2.0', $request->getProtocolVersion());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('r1', $request->getHeaderLine('X-Request-Id'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('2', $request->getHeaderLine('Content-Length'));
        self::assertSame(['session' => 'abc'], $request->getCookieParams());
        self::assertSame(['page' => '2'], $request->getQueryParams());
        self::assertNull($request->getParsedBody());
        self::assertSame('{}', (string) $request->getBody());
    }

    public function testFallsBackToServerNameAndPort(): void
    {
        $request = self::build(['SERVER_NAME' => 'internal', 'SERVER_PORT' => '8080', 'REQUEST_URI' => '/']);

        self::assertSame('http://internal:8080/', (string) $request->getUri());
    }

    public function testKeepsIpv6Hosts(): void
    {
        $request = self::build(['HTTP_HOST' => '[::1]:8080', 'REQUEST_URI' => '/']);

        self::assertSame('[::1]', $request->getUri()->getHost());
        self::assertSame(8080, $request->getUri()->getPort());
    }

    public function testAcceptsQueriesWithBrackets(): void
    {
        $request = self::build(['HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/search?tags[]=a&tags[]=b']);

        self::assertSame('tags%5B%5D=a&tags%5B%5D=b', $request->getUri()->getQuery());
    }

    public function testAbsoluteFormTargetKeepsOnlyPathAndQuery(): void
    {
        $request = self::build(['HTTP_HOST' => 'example.com', 'REQUEST_URI' => 'http://evil.example/a?b=1']);

        self::assertSame('http://example.com/a?b=1', (string) $request->getUri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHosts(): iterable
    {
        yield 'path' => ['example.com/evil'];
        yield 'user info' => ['user@example.com'];
        yield 'bad port' => ['example.com:port'];
        yield 'space' => ['exa mple.com'];
    }

    #[DataProvider('invalidHosts')]
    public function testRejectsInvalidHostHeaders(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::build(['HTTP_HOST' => $host, 'REQUEST_URI' => '/']);
    }

    public function testIgnoresForwardingHeadersForTheUri(): void
    {
        $request = self::build([
            'HTTP_HOST' => 'example.com',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REQUEST_URI' => '/',
        ]);

        self::assertSame('http://example.com/', (string) $request->getUri());
        self::assertSame('evil.example', $request->getHeaderLine('X-Forwarded-Host'));
    }

    public function testRecoversAuthorizationHiddenByApache(): void
    {
        $request = self::build(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer t', 'REQUEST_URI' => '/']);

        self::assertSame('Bearer t', $request->getHeaderLine('Authorization'));
    }

    public function testParsedBodyOnlyForFormPosts(): void
    {
        $form = self::build(['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8', 'REQUEST_URI' => '/'], [
            'a' => '1',
        ]);
        $json = self::build(['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'REQUEST_URI' => '/'], ['a' => '1']);

        self::assertSame(['a' => '1'], $form->getParsedBody());
        self::assertNull($json->getParsedBody());
    }

    public function testNormalisesNestedUploadedFiles(): void
    {
        $request = self::build(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'], files: [
            'avatar' => ['tmp_name' => '/tmp/php1', 'size' => 10, 'error' => UPLOAD_ERR_OK, 'name' => 'a.png', 'type' => 'image/png'],
            'docs' => [
                'tmp_name' => ['x' => ['/tmp/php2', '']],
                'size' => ['x' => [20, 0]],
                'error' => ['x' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE]],
                'name' => ['x' => ['b.pdf', '']],
                'type' => ['x' => ['application/pdf', '']],
            ],
        ]);

        $files = $request->getUploadedFiles();
        $avatar = $files['avatar'] ?? null;
        self::assertInstanceOf(UploadedFileInterface::class, $avatar);
        self::assertSame('a.png', $avatar->getClientFilename());
        self::assertSame(10, $avatar->getSize());

        $docs = $files['docs'] ?? null;
        self::assertIsArray($docs);
        $nested = $docs['x'] ?? null;
        self::assertIsArray($nested);
        $first = $nested[0] ?? null;
        $second = $nested[1] ?? null;
        self::assertInstanceOf(UploadedFileInterface::class, $first);
        self::assertInstanceOf(UploadedFileInterface::class, $second);
        self::assertSame('application/pdf', $first->getClientMediaType());
        self::assertSame(UPLOAD_ERR_NO_FILE, $second->getError());
    }
}
