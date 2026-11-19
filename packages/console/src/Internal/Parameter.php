<?php

declare(strict_types=1);

namespace Shirakaba\Console\Internal;

use BackedEnum;
use DateTimeImmutable;
use ReflectionEnum;
use Shirakaba\Core\Extraction\Scalar;
use Shirakaba\Time\LocalDate;
use Shirakaba\Time\Rfc3339;

/**
 * One parameter of a command and how it is filled.
 *
 * @internal
 */
final readonly class Parameter
{
    /**
     * @param 'argument'|'option'|'output'|'cancellation' $source
     * @param string $type a scalar type, "bool", a backed enum, DateTimeImmutable or LocalDate
     */
    public function __construct(
        public string $name,
        public string $source,
        public string $type = 'string',
        public string $cliName = '',
        public ?string $short = null,
        public bool $hasDefault = false,
        public mixed $default = null,
        public bool $list = false,
        public string $description = '',
    ) {}

    /**
     * @return mixed the converted value, or null when it is invalid
     */
    public function convert(string $value): mixed
    {
        $type = $this->type;

        return match (true) {
            $type === 'string' => $value,
            $type === 'int' => Scalar::int($value),
            $type === 'float' => preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value) === 1 ? (float) $value : null,
            $type === 'bool' => Scalar::bool($value),
            $type === DateTimeImmutable::class => Rfc3339::parse($value),
            $type === LocalDate::class => LocalDate::parse($value),
            enum_exists($type) && is_subclass_of($type, BackedEnum::class) => (string) new ReflectionEnum($type)->getBackingType() === 'int'
                ? (($integer = Scalar::int($value)) === null ? null : $type::tryFrom($integer))
                : $type::tryFrom($value),
            default => null,
        };
    }

    /**
     * What a valid value looks like, for messages and help.
     */
    public function expectation(): string
    {
        $type = $this->type;

        return match (true) {
            $type === 'string' => 'a string',
            $type === 'int' => 'an integer',
            $type === 'float' => 'a number',
            $type === 'bool' => 'true or false',
            $type === DateTimeImmutable::class => 'an RFC 3339 date-time with an offset',
            $type === LocalDate::class => 'a date (YYYY-MM-DD)',
            enum_exists($type) && is_subclass_of($type, BackedEnum::class) => 'one of: '
                . implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $type::cases())),
            default => 'a value',
        };
    }
}
