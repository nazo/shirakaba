<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use DateTimeImmutable;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\Type;
use Shirakaba\Core\Http\ProblemCode;
use Shirakaba\Time\LocalDate;
use Shirakaba\Time\Rfc3339;

/**
 * Maps RFC 3339 date-times to DateTimeImmutable (in UTC) and "YYYY-MM-DD" to LocalDate
 * (design/07-date-and-time.md).
 *
 * @internal
 */
final readonly class TimeNode implements Node
{
    /**
     * @param 'instant'|'date' $kind
     */
    public function __construct(
        private string $kind,
        private bool $nullable,
    ) {}

    public function map(mixed $value, string $pointer, Problems $problems): DateTimeImmutable|LocalDate|null
    {
        if ($value === null && $this->nullable) {
            return null;
        }

        $mapped = !is_string($value)
            ? null
            : match ($this->kind) {
                'instant' => Rfc3339::parse($value),
                'date' => LocalDate::parse($value),
            };
        if ($mapped === null) {
            $problems->mapping(
                $pointer,
                match ($this->kind) {
                    'instant' => 'must be an RFC 3339 date-time with an offset, such as "2026-11-11T09:30:00Z"',
                    'date' => 'must be a date such as "2026-11-11"',
                } . ($this->nullable ? ' or null' : ''),
                $this->kind === 'instant' ? ProblemCode::INVALID_DATE_TIME : ProblemCode::INVALID_DATE,
            );
        }

        return $mapped;
    }

    public function type(): Type
    {
        return new ScalarType('string', $this->nullable, $this->kind === 'instant' ? 'date-time' : 'date');
    }
}
