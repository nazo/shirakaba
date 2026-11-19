<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use BackedEnum;
use JsonException;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use Shirakaba\Core\Description\DescribesResponse;
use Shirakaba\Core\Description\MixedType;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Core\Http\ResponseContext;
use Shirakaba\Core\Http\ResponseValue;
use Shirakaba\Json\Internal\EncodedMap;
use UnexpectedValueException;

/**
 * A JSON response (design/06-json-and-openapi.md).
 *
 * Only arrays, scalars, null, backed enums, JsonSerializable objects and #[JsonDto]
 * objects (encoded from their constructor fields) are encoded. Any other object anywhere
 * in the data makes the response fail with a 500, so an entity that holds secrets is
 * never serialised by accident.
 */
final readonly class Json implements ResponseValue, DescribesResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public mixed $data,
        /** Null for the route's declared success status (design/06-json-and-openapi.md), or 200. */
        public ?int $status = null,
        public array $headers = [],
    ) {}

    /**
     * @throws UnexpectedValueException when the data holds an object that is not JsonSerializable
     * @throws JsonException when the data cannot be encoded, for example because of invalid UTF-8
     */
    public function toResponse(ResponseContext $context): ResponseInterface
    {
        $json = self::encode($this->data);

        $response = $context
            ->responses
            ->createResponse($context->status($this->status))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($context->streams->createStream($json));
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Encodes data with the rules of Json responses: #[JsonDto] objects by their fields,
     * instants as RFC 3339 in UTC, LocalDate as YYYY-MM-DD, enums by value.
     *
     * @throws UnexpectedValueException when the data holds an object that cannot be encoded
     * @throws JsonException when the data cannot be encoded, for example because of invalid UTF-8
     */
    public static function encode(mixed $data): string
    {
        return json_encode(
            self::prepare($data, ''),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public static function describeResponse(): ResponseDescription
    {
        return new ResponseDescription(200, 'application/json', new MixedType());
    }

    /**
     * Turns #[JsonDto] objects into arrays, date-times into RFC 3339 strings in UTC and
     * LocalDate into "YYYY-MM-DD" (design/07-date-and-time.md), and refuses any other object that is not
     * JsonSerializable or a backed enum.
     */
    private static function prepare(mixed $value, string $path): mixed
    {
        if (is_array($value)) {
            $prepared = [];
            foreach ($value as $key => $item) {
                $prepared[$key] = self::prepare($item, $path . '/' . $key);
            }

            return $prepared;
        }
        if ($value instanceof EncodedMap) {
            // An object even when empty or keyed 0, 1, ....
            return (object) self::prepare($value->items, $path);
        }
        if (!is_object($value) || $value instanceof JsonSerializable || $value instanceof BackedEnum) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \Shirakaba\Time\Rfc3339::format($value);
        }
        if ($value instanceof \Shirakaba\Time\LocalDate) {
            return $value->toString();
        }
        if (new ReflectionClass($value)->getAttributes(JsonDto::class) !== []) {
            // Always an object, even without fields or with names like "0".
            return (object) self::prepare(JsonDto::fields($value), $path);
        }

        throw new UnexpectedValueException(sprintf(
            'Json refuses to encode %s at "%s": mark the class #[JsonDto], implement JsonSerializable, or convert it to an array.',
            $value::class,
            $path === '' ? '/' : $path,
        ));
    }
}
