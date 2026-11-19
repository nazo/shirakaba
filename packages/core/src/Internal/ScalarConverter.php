<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionEnum;
use ReflectionNamedType;
use Shirakaba\Core\Description\EnumType;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\Type;
use Shirakaba\Core\Extraction\Constraint;
use Shirakaba\Core\Extraction\ExtractionFailure;
use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\Extraction\RequestValue;
use Shirakaba\Core\Extraction\Scalar;
use Shirakaba\Core\Http\ProblemCode;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Time\LocalDate;
use Shirakaba\Time\Rfc3339;

/**
 * Converts request strings to a parameter's declared scalar type, strictly (design/04-routing-and-handlers.md).
 *
 * @internal
 */
final readonly class ScalarConverter
{
    /**
     * @param 'string'|'int'|'bool'|'enum'|'value'|'instant'|'date' $kind
     * @param class-string<BackedEnum>|null $enum
     * @param class-string<RequestValue>|null $valueClass
     * @param list<Constraint> $constraints
     */
    private function __construct(
        private string $kind,
        private ?string $enum,
        private bool $intBacked,
        /** What a valid value looks like, for error messages. */
        public string $expectation,
        /** Whether the parameter has a default value. */
        public bool $hasDefault,
        public mixed $default,
        private bool $nullable = false,
        public array $constraints = [],
        private ?string $valueClass = null,
    ) {}

    /**
     * The neutral description of the accepted values (design/04-routing-and-handlers.md).
     */
    public function type(): Type
    {
        $enum = $this->enum;
        $valueClass = $this->valueClass;
        if ($valueClass !== null) {
            $type = $valueClass::requestValueType();

            return $this->nullable ? self::nullable($type) : $type;
        }

        return match (true) {
            $enum !== null => EnumType::of($enum, $this->nullable),
            $this->kind === 'enum' => new ScalarType('string', $this->nullable),
            $this->kind === 'value' => new ScalarType('string', $this->nullable),
            $this->kind === 'instant' => new ScalarType('string', $this->nullable, 'date-time'),
            $this->kind === 'date' => new ScalarType('string', $this->nullable, 'date'),
            default => new ScalarType($this->kind, $this->nullable),
        };
    }

    private static function nullable(Type $type): Type
    {
        return match (true) {
            $type instanceof ScalarType => new ScalarType($type->name, true, $type->format),
            $type instanceof EnumType => new EnumType($type->class, $type->values, true),
            default => $type,
        };
    }

    /**
     * Converts and applies the parameter's constraints.
     *
     * @param string $source such as "query parameter", for error messages
     * @throws ExtractionFailure when the value is not valid for the type or a constraint
     */
    public function extract(string $value, string $source, string $name): string|int|bool|BackedEnum|RequestValue|DateTimeImmutable|LocalDate
    {
        $converted = $this->convert($value) ?? throw ExtractionFailure::invalid($source, $name, $this->expectation, match ($this->kind) {
            'instant' => ProblemCode::INVALID_DATE_TIME,
            'date' => ProblemCode::INVALID_DATE,
            default => ProblemCode::INVALID_PARAMETER,
        });
        $violation = Constraints::violation($this->constraints, $converted);
        if ($violation !== null) {
            throw new ExtractionFailure(sprintf('Invalid %s "%s": %s.', $source, $name, $violation));
        }

        return $converted;
    }

    /**
     * The value used when the request does not provide one.
     *
     * @throws ExtractionFailure when there is no default
     */
    public function missing(string $source, string $name): mixed
    {
        return $this->hasDefault ? $this->default : throw ExtractionFailure::missing($source, $name);
    }

    public function describe(string $source, string $name, string $parameter): ParameterDescription
    {
        return new ParameterDescription(
            $source,
            $name,
            $parameter,
            $this->type(),
            required: !$this->hasDefault,
            hasDefault: $this->hasDefault,
            default: $this->hasDefault && $this->default instanceof BackedEnum ? $this->default->value : $this->default,
            constraints: $this->valueClass !== null ? [...$this->valueClass::requestValueConstraints(), ...$this->constraints] : $this->constraints,
        );
    }

    /**
     * @throws InvalidApplication when the parameter's type is not a supported scalar
     */
    public static function for(HandlerParameter $parameter, string $extractor): self
    {
        $type = $parameter->reflection->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new InvalidApplication(sprintf(
                '%s: #[%s] needs a single declared type (string, int, bool, a backed enum, DateTimeImmutable, LocalDate or a RequestValue), optionally nullable.',
                $parameter->describe(),
                $extractor,
            ));
        }

        $hasDefault = $parameter->reflection->isDefaultValueAvailable();
        $default = $hasDefault ? $parameter->reflection->getDefaultValue() : null;
        $nullable = $type->allowsNull();
        $constraints = Constraints::of($parameter->reflection);
        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match ($name) {
                'string' => new self('string', null, false, 'a string', $hasDefault, $default, $nullable, $constraints),
                'int' => new self('int', null, false, 'an integer', $hasDefault, $default, $nullable, $constraints),
                'bool' => new self('bool', null, false, 'a boolean (true, false, 1 or 0)', $hasDefault, $default, $nullable, $constraints),
                default => throw new InvalidApplication(sprintf(
                    '%s: #[%s] does not support the type %s; use string, int, bool, a backed enum, DateTimeImmutable, LocalDate or a RequestValue.',
                    $parameter->describe(),
                    $extractor,
                    $name,
                )),
            };
        }

        if ($name === DateTimeImmutable::class) {
            return new self(
                'instant',
                null,
                false,
                'an RFC 3339 date-time with an offset, such as 2026-11-11T09:30:00Z',
                $hasDefault,
                $default,
                $nullable,
                $constraints,
            );
        }
        if ($name === LocalDate::class) {
            return new self('date', null, false, 'a date (YYYY-MM-DD)', $hasDefault, $default, $nullable, $constraints);
        }
        if (is_a($name, DateTimeInterface::class, true)) {
            throw new InvalidApplication(sprintf(
                '%s: #[%s] needs DateTimeImmutable for instants (design/07-date-and-time.md); %s is mutable or too wide.',
                $parameter->describe(),
                $extractor,
                $name,
            ));
        }

        if (is_subclass_of($name, RequestValue::class)) {
            $short = substr((string) strrchr('\\' . $name, '\\'), 1);

            return new self('value', null, false, 'a valid ' . $short, $hasDefault, $default, $nullable, $constraints, $name);
        }

        if (!enum_exists($name) || !is_subclass_of($name, BackedEnum::class)) {
            throw new InvalidApplication(sprintf(
                '%s: #[%s] does not support the type %s; use string, int, bool, a backed enum, DateTimeImmutable, LocalDate or a RequestValue.',
                $parameter->describe(),
                $extractor,
                $name,
            ));
        }

        $intBacked = (string) new ReflectionEnum($name)->getBackingType() === 'int';
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $name::cases());

        return new self('enum', $name, $intBacked, 'one of: ' . implode(', ', $values), $hasDefault, $default, $nullable, $constraints);
    }

    /**
     * @return string|int|bool|BackedEnum|RequestValue|DateTimeImmutable|LocalDate|null null when the value is not valid for the type
     */
    public function convert(string $value): string|int|bool|BackedEnum|RequestValue|DateTimeImmutable|LocalDate|null
    {
        $valueClass = $this->valueClass;

        return match ($this->kind) {
            'string' => $value,
            'int' => Scalar::int($value),
            'bool' => Scalar::bool($value),
            'enum' => $this->enumCase($value),
            'value' => $valueClass === null ? null : $valueClass::fromRequestValue($value),
            'instant' => Rfc3339::parse($value),
            'date' => LocalDate::parse($value),
        };
    }

    private function enumCase(string $value): ?BackedEnum
    {
        $enum = $this->enum;
        if ($enum === null) {
            return null;
        }
        if ($this->intBacked) {
            $integer = Scalar::int($value);

            return $integer === null ? null : $enum::tryFrom($integer);
        }

        return $enum::tryFrom($value);
    }
}
