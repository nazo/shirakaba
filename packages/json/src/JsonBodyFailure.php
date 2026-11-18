<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\ProblemCode;

/**
 * A JSON request body was rejected (design/06-json-and-openapi.md). Field errors are listed in the Problem
 * Details member "errors" as JSON Pointers with details; received values are never echoed.
 */
final class JsonBodyFailure extends HttpFailure
{
    /**
     * @param 400|422 $status
     * @param list<array{pointer: string, detail: string, code: string}> $errors
     */
    public static function fields(int $status, array $errors): self
    {
        return new self(
            $status,
            $status === 422 ? 'The request body has invalid values.' : 'The request body does not match the expected structure.',
            extensions: ['code' => $status === 422 ? ProblemCode::INVALID_VALUES : ProblemCode::INVALID_BODY, 'errors' => $errors],
        );
    }

    public static function unsupportedMediaType(): self
    {
        return new self(415, 'Expected a JSON body (Content-Type: application/json).', headers: ['Accept' => 'application/json'], extensions: ['code' => ProblemCode::UNSUPPORTED_MEDIA_TYPE]);
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(413, sprintf('The request body must not exceed %d bytes.', $maxBytes), extensions: ['code' => ProblemCode::BODY_TOO_LARGE]);
    }

    public static function tooManyMembers(int $maxMembers): self
    {
        return new self(413, sprintf('The request body must not have more than %d object members.', $maxMembers), extensions: ['code' => ProblemCode::BODY_TOO_LARGE]);
    }

    public static function malformed(): self
    {
        return new self(400, 'The request body is not valid JSON.', extensions: ['code' => ProblemCode::MALFORMED_BODY]);
    }
}
