<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * Immutable PSR-7 server-side (incoming) request.
 *
 * Nothing here reads superglobals; runtime packages build server requests from their
 * own environment and pass every value in explicitly.
 */
final class ServerRequest extends RequestBase implements ServerRequestInterface
{
    /** @var array<array-key, mixed> */
    private readonly array $uploadedFiles;

    /** @var array<array-key, mixed>|object|null */
    private readonly array|object|null $parsedBody;

    /**
     * @param array<array-key, mixed> $headers name => string, int or non-empty list of those
     * @param array<array-key, mixed> $serverParams
     * @param array<array-key, mixed> $cookieParams
     * @param array<array-key, mixed> $queryParams
     * @param array<array-key, mixed> $uploadedFiles tree whose leaves are UploadedFileInterface instances
     * @param array<array-key, mixed>|object|null $parsedBody
     * @param array<array-key, mixed> $attributes PHP turns numeric names into int keys
     * @throws InvalidArgumentException when the method, URI, a header or the uploaded file tree is invalid
     * @throws \RuntimeException when a string body cannot be stored in a temporary stream
     */
    public function __construct(
        string $method,
        UriInterface|string $uri,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $protocolVersion = '1.1',
        private readonly array $serverParams = [],
        private readonly array $cookieParams = [],
        private readonly array $queryParams = [],
        array $uploadedFiles = [],
        array|object|null $parsedBody = null,
        private readonly array $attributes = [],
    ) {
        parent::__construct($method, $uri, $headers, $body, $protocolVersion);
        $this->uploadedFiles = self::filterUploadedFiles($uploadedFiles);
        $this->parsedBody = $parsedBody;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @param array<array-key, mixed> $cookies
     */
    #[\NoDiscard]
    public function withCookieParams(array $cookies): static
    {
        return clone($this, ['cookieParams' => $cookies]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[\NoDiscard]
    public function withQueryParams(array $query): static
    {
        return clone($this, ['queryParams' => $query]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param array<array-key, mixed> $uploadedFiles
     */
    #[\NoDiscard]
    public function withUploadedFiles(array $uploadedFiles): static
    {
        return clone($this, ['uploadedFiles' => self::filterUploadedFiles($uploadedFiles)]);
    }

    /**
     * @return array<array-key, mixed>|object|null
     */
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    /**
     * @param array<array-key, mixed>|object|null $data
     */
    #[\NoDiscard]
    public function withParsedBody($data): static
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException(sprintf('The parsed body must be an array, an object or null, %s given.', get_debug_type($data)));
        }

        return clone($this, ['parsedBody' => $data]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param mixed $default
     */
    public function getAttribute(string $name, $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /**
     * @param mixed $value
     */
    #[\NoDiscard]
    public function withAttribute(string $name, $value): static
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return clone($this, ['attributes' => $attributes]);
    }

    #[\NoDiscard]
    public function withoutAttribute(string $name): static
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $this;
        }

        $attributes = $this->attributes;
        unset($attributes[$name]);

        return clone($this, ['attributes' => $attributes]);
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private static function filterUploadedFiles(array $files): array
    {
        foreach ($files as $file) {
            if (is_array($file)) {
                self::filterUploadedFiles($file);
            } elseif (!$file instanceof UploadedFileInterface) {
                throw new InvalidArgumentException(sprintf('Uploaded files must be UploadedFileInterface instances, %s given.', get_debug_type($file)));
            }
        }

        return $files;
    }
}
