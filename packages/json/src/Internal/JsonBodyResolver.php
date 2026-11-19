<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use JsonException;
use Psr\Http\Message\StreamInterface;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\JsonBodyFailure;

/**
 * Reads and maps a JSON body. A plain serialisable object, so build plans can be cached (design/04-routing-and-handlers.md).
 *
 * @internal
 */
final readonly class JsonBodyResolver implements ArgumentResolver
{
    /**
     * @param positive-int $maxBytes
     * @param positive-int $maxDepth
     * @param positive-int $maxMembers
     */
    public function __construct(
        private ObjectNode|UnionNode $node,
        private int $maxBytes,
        private int $maxDepth,
        private int $maxMembers = 10_000,
    ) {}

    public function resolve(Exchange $exchange): mixed
    {
        $request = $exchange->request;
        if (!JsonBody::isJsonMediaType($request->getHeaderLine('Content-Type'))) {
            throw JsonBodyFailure::unsupportedMediaType();
        }
        $declared = $request->getHeaderLine('Content-Length');
        if (ctype_digit($declared) && (strlen($declared) > 18 || (int) $declared > $this->maxBytes)) {
            throw JsonBodyFailure::tooLarge($this->maxBytes);
        }

        $raw = self::read($request->getBody(), $this->maxBytes);
        if (self::members($raw) > $this->maxMembers) {
            throw JsonBodyFailure::tooManyMembers($this->maxMembers);
        }
        try {
            // Objects stay stdClass, so "{}" and "[]" remain distinguishable.
            $document = json_decode($raw, false, $this->maxDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw JsonBodyFailure::malformed();
        }

        $problems = new Problems();
        $value = $this->node->map($document, '', $problems);
        if ($problems->mapping !== []) {
            throw JsonBodyFailure::fields(400, $problems->mapping);
        }
        if ($problems->constraints !== []) {
            throw JsonBodyFailure::fields(422, $problems->constraints);
        }

        return $value;
    }

    /**
     * Counts the colons outside strings, an upper bound on the object members of valid JSON.
     * Possessive quantifiers keep the scan linear; a failed scan counts as too many.
     */
    private static function members(string $raw): int
    {
        $count = preg_match_all('/"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*FAIL)|:/', $raw);

        return $count === false ? PHP_INT_MAX : $count;
    }

    private static function read(StreamInterface $body, int $maxBytes): string
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = '';
        while (!$body->eof() && strlen($raw) <= $maxBytes) {
            $chunk = $body->read(min(65_536, $maxBytes + 1 - strlen($raw)));
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        if (strlen($raw) > $maxBytes) {
            throw JsonBodyFailure::tooLarge($maxBytes);
        }

        return $raw;
    }
}
