<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\ProblemCode;

/**
 * A handler argument could not be extracted from the request: a 400 (design/04-routing-and-handlers.md).
 *
 * The detail names the parameter and the reason but never echoes the received value.
 */
final class ExtractionFailure extends HttpFailure
{
    /**
     * @param string $code a ProblemCode, written as the problem's `code` member
     */
    public function __construct(string $detail, string $code = ProblemCode::INVALID_PARAMETER)
    {
        parent::__construct(400, $detail, extensions: ['code' => $code]);
    }

    public static function missing(string $source, string $name): self
    {
        return new self(sprintf('Missing %s "%s".', $source, $name), ProblemCode::MISSING_PARAMETER);
    }

    /**
     * @param string $code ProblemCode::INVALID_DATE_TIME or INVALID_DATE for dates, so clients
     *                     can tell a missing offset from other mistakes
     */
    public static function invalid(string $source, string $name, string $expectation, string $code = ProblemCode::INVALID_PARAMETER): self
    {
        return new self(sprintf('Invalid %s "%s": expected %s.', $source, $name, $expectation), $code);
    }

    public static function repeated(string $source, string $name): self
    {
        return new self(sprintf('The %s "%s" must appear at most once.', $source, $name), ProblemCode::REPEATED_PARAMETER);
    }
}
