<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

/**
 * Stable codes for the Problem Details Shirakaba sends to clients: a `code` member on
 * the problem, and a `code` on each entry of `errors`. Clients and tests can branch on them
 * without parsing the English detail. Each code has a page in shirakaba/docs
 * (`problems/<code>.md`); a test checks both ways.
 */
final readonly class ProblemCode
{
    // Problems: the whole request.
    public const string MISSING_PARAMETER = 'missing-parameter';
    public const string INVALID_PARAMETER = 'invalid-parameter';
    public const string REPEATED_PARAMETER = 'repeated-parameter';
    public const string INVALID_BODY = 'invalid-body';
    public const string INVALID_VALUES = 'invalid-values';
    public const string MALFORMED_BODY = 'malformed-body';
    public const string UNSUPPORTED_MEDIA_TYPE = 'unsupported-media-type';
    public const string BODY_TOO_LARGE = 'body-too-large';
    public const string RATE_LIMITED = 'rate-limited';

    // Entries of `errors`: one field each.
    public const string REQUIRED = 'required';
    public const string UNKNOWN_FIELD = 'unknown-field';
    public const string INVALID_TYPE = 'invalid-type';
    public const string INVALID_DATE_TIME = 'invalid-date-time';
    public const string INVALID_DATE = 'invalid-date';
    public const string INVALID_CHOICE = 'invalid-choice';
    public const string CONSTRAINT = 'constraint';

    /**
     * Every code, for documentation checks.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_filter(new \ReflectionClass(self::class)->getConstants(), is_string(...)));
    }
}
