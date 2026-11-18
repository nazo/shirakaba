<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Immutable PSR-7 response.
 */
final class Response extends Message implements ResponseInterface
{
    /** Reason phrases from the IANA HTTP Status Code Registry.
     * @var array<int, string>
     */
    private const array REASON_PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    private readonly int $statusCode;
    private readonly string $reasonPhrase;

    /**
     * @param array<array-key, mixed> $headers name => string, int or non-empty list of those
     * @param string $reasonPhrase empty to use the registered phrase for the status code
     * @throws InvalidArgumentException when the status code, reason phrase or a header is invalid
     * @throws \RuntimeException when a string body cannot be stored in a temporary stream
     */
    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $protocolVersion = '1.1',
        string $reasonPhrase = '',
    ) {
        parent::__construct($headers, $body, $protocolVersion);
        $this->statusCode = self::filterStatusCode($statusCode);
        $this->reasonPhrase = self::filterReasonPhrase($statusCode, $reasonPhrase);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[\NoDiscard]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return clone($this, [
            'statusCode' => self::filterStatusCode($code),
            'reasonPhrase' => self::filterReasonPhrase($code, $reasonPhrase),
        ]);
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    private static function filterStatusCode(int $code): int
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP status code %d; it must be between 100 and 599.', $code));
        }

        return $code;
    }

    private static function filterReasonPhrase(int $code, string $reasonPhrase): string
    {
        if ($reasonPhrase === '') {
            return self::REASON_PHRASES[$code] ?? '';
        }
        if (preg_match('/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D', $reasonPhrase) !== 1) {
            throw new InvalidArgumentException('A reason phrase must not contain control characters such as CR, LF or NUL.');
        }

        return $reasonPhrase;
    }
}
