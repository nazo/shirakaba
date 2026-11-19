<?php

declare(strict_types=1);

namespace Shirakaba\Core\Testing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Http\Message\HttpFactory;

/**
 * Calls an application in-process, without a server or superglobals.
 *
 *     $client = new TestClient($application);
 *     $response = $client->get('/users/42?verbose=true');
 *
 * Query parameters and the Host header are filled the way a runtime would fill them.
 * Cookies, redirects and HTML parsing are out of scope.
 */
final readonly class TestClient
{
    private ServerRequestFactoryInterface $requests;
    private StreamFactoryInterface $streams;

    public function __construct(
        private RequestHandlerInterface $application,
        ?ServerRequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        private string $baseUri = 'http://localhost',
    ) {
        $factory = new HttpFactory();
        $this->requests = $requests ?? $factory;
        $this->streams = $streams ?? $factory;
    }

    /**
     * @param string $target path and optional query, such as "/users?page=2"
     * @param array<string, string|list<string>> $headers
     * @param array<array-key, mixed> $serverParams
     */
    public function request(string $method, string $target, array $headers = [], string $body = '', array $serverParams = []): ResponseInterface
    {
        $request = $this->requests->createServerRequest($method, $this->baseUri . $target, $serverParams);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);
        if ($body !== '') {
            $request = $request->withBody($this->streams->createStream($body));
        }

        return $this->application->handle($request);
    }

    /**
     * Sends a multipart form the way a runtime would present it: fields as the parsed body,
     * files as uploaded files.
     *
     * @param array<string, string|list<string>> $fields
     * @param array<string, UploadedFileInterface> $files
     * @param array<string, string|list<string>> $headers
     */
    public function form(string $method, string $target, array $fields, array $files = [], array $headers = []): ResponseInterface
    {
        $request = $this->requests
            ->createServerRequest($method, $this->baseUri . $target)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=shirakaba-test-client')
            ->withParsedBody($fields)
            ->withUploadedFiles($files);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->application->handle($request);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function get(string $target, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $target, $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function head(string $target, array $headers = []): ResponseInterface
    {
        return $this->request('HEAD', $target, $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function options(string $target, array $headers = []): ResponseInterface
    {
        return $this->request('OPTIONS', $target, $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function post(string $target, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('POST', $target, $headers, $body);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function put(string $target, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('PUT', $target, $headers, $body);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function patch(string $target, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('PATCH', $target, $headers, $body);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function delete(string $target, array $headers = []): ResponseInterface
    {
        return $this->request('DELETE', $target, $headers);
    }
}
