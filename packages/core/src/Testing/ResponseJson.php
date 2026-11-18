<?php

declare(strict_types=1);

namespace Shirakaba\Core\Testing;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

/**
 * Reads JSON response bodies in tests without hand-written type juggling:
 *
 *     ResponseJson::at($response, '/items/0/title'); // JSON Pointer (RFC 6901)
 */
final readonly class ResponseJson
{
    /**
     * @throws JsonException when the body is not JSON
     */
    public static function decode(ResponseInterface $response): mixed
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return json_decode($body->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws UnexpectedValueException when the pointer does not exist in the document
     * @throws JsonException when the body is not JSON
     */
    public static function at(ResponseInterface $response, string $pointer): mixed
    {
        $value = self::decode($response);
        if ($pointer === '') {
            return $value;
        }

        foreach (array_slice(explode('/', $pointer), 1) as $segment) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new UnexpectedValueException(sprintf('The response JSON has no "%s".', $pointer));
            }
            $value = $value[$key];
        }

        return $value;
    }
}
