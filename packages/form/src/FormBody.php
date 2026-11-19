<?php

declare(strict_types=1);

namespace Shirakaba\Form;

use Attribute;
use BackedEnum;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionNamedType;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\EnumType;
use Shirakaba\Core\Description\FileType;
use Shirakaba\Core\Description\ListType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Description\Property;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\Type;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\BodyExtractor;
use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\Extraction\Scalar;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\ProblemCode;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Form\Internal\FormBodyResolver;
use Shirakaba\Form\Internal\FormField;
use Shirakaba\Form\Internal\FormPlanner;
use Shirakaba\Form\Internal\Invalid;

/**
 * Maps an HTML form (application/x-www-form-urlencoded or multipart/form-data) into a
 * DTO: `#[FormBody] SignUp $input`. Errors use the format of shirakaba/json (design/06-json-and-openapi.md):
 * 415, 413, 400 with field errors, 422 for constraint violations.
 *
 * Form conventions: a checkbox sends "on" (true) or nothing (the default); an empty
 * string is null for nullable non-string fields; repeated fields use `name[]` and an
 * array parameter with #[FormList]. Uploaded files are UploadedFileInterface parameters,
 * restricted with MaxFileSize and FileTypes. `<input type="date">` maps to LocalDate;
 * DateTimeImmutable needs an RFC 3339 value with an offset, so `datetime-local` inputs,
 * which have none, need a string field and an explicit timezone (design/07-date-and-time.md).
 *
 * URL-encoded bodies are parsed from the raw body, because PHP's $_POST renames keys
 * ("a.b" becomes "a_b"). Multipart bodies use what the runtime parsed, where PHP's
 * max_input_vars applies. URL-encoded bodies are limited to $maxFields fields for the same
 * reason: PHP's hash tables are not randomised, so colliding field names cost quadratic
 * time (about a second for 1 MiB).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FormBody implements BodyExtractor, DescribesParameter
{
    private const string URLENCODED = 'application/x-www-form-urlencoded';
    private const string MULTIPART = 'multipart/form-data';

    /**
     * @param positive-int $maxBytes limit for URL-encoded bodies; multipart limits are the SAPI's
     * @param positive-int $maxFields limit for URL-encoded bodies, counting each repeated name
     * @throws InvalidArgumentException when a limit is not positive
     */
    public function __construct(
        public int $maxBytes = 1_048_576,
        public bool $allowUnknownFields = false,
        public int $maxFields = 1000,
    ) {
        if ($maxBytes < 1 || $maxFields < 1) {
            throw new InvalidArgumentException('FormBody limits must be positive.');
        }
    }

    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        [$class, $fields] = $this->fields($parameter);
        $maxBytes = $this->maxBytes;
        $allowUnknown = $this->allowUnknownFields;
        $maxFields = $this->maxFields;

        return new FormBodyResolver($class, $fields, $maxBytes, $allowUnknown, $maxFields);
    }

    public function describe(HandlerParameter $parameter): ParameterDescription
    {
        [$class, $fields] = $this->fields($parameter);
        $hasFiles = array_any($fields, static fn (FormField $field): bool => $field->kind === 'file');

        return new ParameterDescription(
            ParameterDescription::BODY,
            '',
            $parameter->name(),
            new ObjectType(
                $class,
                array_map(static fn (FormField $field): Property => new Property(
                    $field->name,
                    self::type($field),
                    required: !$field->hasDefault,
                    hasDefault: $field->hasDefault && $field->kind !== 'file',
                    default: $field->default instanceof BackedEnum ? $field->default->value : ($field->kind === 'file' ? null : $field->default),
                    constraints: $field->constraints,
                    description: $field->description,
                ), $fields),
                $this->allowUnknownFields,
            ),
            required: true,
            mediaTypes: $hasFiles ? [self::MULTIPART] : [self::URLENCODED, self::MULTIPART],
        );
    }

    /**
     * Normalises the request into field values and uploaded files.
     *
     * @return array{array<string, list<string>>, array<array-key, mixed>}
     * @throws HttpFailure for an unsupported media type (415) or an oversized body (413)
     * @internal
     */
    public static function input(ServerRequestInterface $request, int $maxBytes, int $maxFields = 1000): array
    {
        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if ($mediaType === self::URLENCODED) {
            $raw = self::read($request, $maxBytes);
            if (substr_count($raw, '&') >= $maxFields) {
                throw new HttpFailure(413, sprintf('The form must not have more than %d fields.', $maxFields), extensions: [
                    'code' => ProblemCode::BODY_TOO_LARGE,
                ]);
            }

            return [self::parseUrlEncoded($raw), []];
        }
        if ($mediaType !== self::MULTIPART) {
            throw new HttpFailure(415, 'Expected a form body (application/x-www-form-urlencoded or multipart/form-data).', extensions: [
                'code' => ProblemCode::UNSUPPORTED_MEDIA_TYPE,
            ]);
        }

        $values = [];
        $parsed = $request->getParsedBody();
        foreach (is_array($parsed) ? $parsed : [] as $name => $value) {
            $strings = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                // Nested structures cannot map to a field; an empty list makes it fail as malformed.
                if (!is_string($item)) {
                    $strings = [];
                    break;
                }
                $strings[] = $item;
            }
            $values[(string) $name] = $strings;
        }

        return [$values, $request->getUploadedFiles()];
    }

    /**
     * @param class-string $class
     * @param list<FormField> $fields
     * @param array<string, list<string>> $values
     * @param array<array-key, mixed> $files
     * @throws HttpFailure 400 with field errors, 413 for files over the SAPI limit, 422 for constraint violations
     * @internal
     */
    public static function map(string $class, array $fields, array $values, array $files, bool $allowUnknown): object
    {
        $mapping = [];
        $violations = [];
        $arguments = [];
        $known = [];
        foreach ($fields as $field) {
            $known[$field->name] = true;
            $pointer = '/' . $field->name;

            if ($field->kind === 'file') {
                $file = $files[$field->name] ?? null;
                if ($file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    throw new HttpFailure(413, sprintf('The file "%s" exceeds the upload size limit.', $field->name), extensions: [
                        'code' => ProblemCode::BODY_TOO_LARGE,
                    ]);
                }
                if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                    if (!$field->hasDefault) {
                        $mapping[] = ['pointer' => $pointer, 'detail' => 'is required', 'code' => ProblemCode::REQUIRED];
                    }
                    continue;
                }
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    $mapping[] = ['pointer' => $pointer, 'detail' => 'could not be uploaded', 'code' => ProblemCode::INVALID_TYPE];
                    continue;
                }
                $value = $file;
            } else {
                $raw = $values[$field->name] ?? null;
                if ($raw === null) {
                    if (!$field->hasDefault) {
                        $mapping[] = ['pointer' => $pointer, 'detail' => 'is required', 'code' => ProblemCode::REQUIRED];
                    }
                    continue;
                }
                if ($raw === []) {
                    $mapping[] = ['pointer' => $pointer, 'detail' => 'has an unsupported structure', 'code' => ProblemCode::INVALID_TYPE];
                    continue;
                }
                if (!$field->list && count($raw) !== 1) {
                    $mapping[] = ['pointer' => $pointer, 'detail' => 'must appear at most once', 'code' => ProblemCode::INVALID_TYPE];
                    continue;
                }
                $converted = array_map(static fn (string $item): mixed => self::convert($field, $item), $raw);
                if (in_array(Invalid::Value, $converted, true)) {
                    $mapping[] = ['pointer' => $pointer, 'detail' => 'must be ' . self::expectation($field), 'code' => self::code($field)];
                    continue;
                }
                $value = $field->list ? $converted : $converted[0];
            }

            foreach ($field->constraints as $constraint) {
                $violation = $value === null ? null : $constraint->violation($value instanceof BackedEnum ? $value->value : $value);
                if ($violation !== null) {
                    $violations[] = ['pointer' => $pointer, 'detail' => $violation, 'code' => ProblemCode::CONSTRAINT];
                    break;
                }
            }
            $arguments[$field->name] = $value;
        }

        if (!$allowUnknown) {
            foreach ([...array_keys($values), ...array_keys($files)] as $name) {
                if (!isset($known[(string) $name])) {
                    $mapping[] = ['pointer' => '/' . $name, 'detail' => 'is not an accepted field', 'code' => ProblemCode::UNKNOWN_FIELD];
                }
            }
        }

        if ($mapping !== []) {
            throw new HttpFailure(400, 'The form does not match the expected fields.', extensions: ['code' => ProblemCode::INVALID_BODY, 'errors' => $mapping]);
        }
        if ($violations !== []) {
            throw new HttpFailure(422, 'The form has invalid values.', extensions: ['code' => ProblemCode::INVALID_VALUES, 'errors' => $violations]);
        }

        try {
            return new $class(...$arguments);
        } catch (InvalidArgumentException) {
            throw new HttpFailure(422, 'The form has invalid values.', extensions: [
                'code' => ProblemCode::INVALID_VALUES,
                'errors' => [['pointer' => '/', 'detail' => 'is invalid', 'code' => ProblemCode::CONSTRAINT]],
            ]);
        }
    }

    /**
     * @return array{class-string, list<FormField>}
     */
    private function fields(HandlerParameter $parameter): array
    {
        $type = $parameter->reflection->getType();
        $class = $type instanceof ReflectionNamedType && !$type->isBuiltin() && !$type->allowsNull() ? $type->getName() : null;
        if ($class === null || !class_exists($class)) {
            throw new InvalidApplication(sprintf('%s: #[FormBody] needs a non-nullable DTO class type.', $parameter->describe()));
        }

        return [$class, FormPlanner::fields($class, $parameter->describe())];
    }

    private static function read(ServerRequestInterface $request, int $maxBytes): string
    {
        $body = $request->getBody();
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
            throw new HttpFailure(413, sprintf('The request body must not exceed %d bytes.', $maxBytes), extensions: ['code' => ProblemCode::BODY_TOO_LARGE]);
        }

        return $raw;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseUrlEncoded(string $body): array
    {
        $values = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            $separator = strpos($pair, '=');
            $name = urldecode($separator === false ? $pair : substr($pair, 0, $separator));
            if (str_ends_with($name, '[]')) {
                $name = substr($name, 0, -2);
            }
            $values[$name][] = $separator === false ? '' : urldecode(substr($pair, $separator + 1));
        }

        return $values;
    }

    private static function convert(FormField $field, string $value): mixed
    {
        if ($value === '' && $field->nullable && $field->kind !== 'string') {
            return null;
        }
        $enum = $field->enum;

        $converted = match ($field->kind) {
            'string' => $value,
            'int' => Scalar::int($value),
            'float' => preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value) === 1 ? (float) $value : null,
            'bool' => match ($value) {
                'true', '1', 'on' => true,
                'false', '0', 'off' => false,
                default => null,
            },
            'enum' => $enum === null
                ? null
                : (
                    $field->intBacked
                        ? (($integer = Scalar::int($value)) === null ? null : $enum::tryFrom($integer))
                        : $enum::tryFrom($value)
                ),
            'instant' => \Shirakaba\Time\Rfc3339::parse($value),
            'date' => \Shirakaba\Time\LocalDate::parse($value),
            'file' => null,
        };

        return $converted ?? Invalid::Value;
    }

    private static function expectation(FormField $field): string
    {
        $enum = $field->enum;

        return match ($field->kind) {
            'string' => 'a string',
            'int' => 'an integer',
            'float' => 'a number',
            'bool' => 'a boolean (true, false, 1, 0, on or off)',
            'enum' => $enum === null
                ? 'valid'
                : 'one of: ' . implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases())),
            'instant' => 'an RFC 3339 date-time with an offset',
            'date' => 'a date (YYYY-MM-DD)',
            'file' => 'a file',
        };
    }

    private static function code(FormField $field): string
    {
        return match ($field->kind) {
            'enum' => ProblemCode::INVALID_CHOICE,
            'instant' => ProblemCode::INVALID_DATE_TIME,
            'date' => ProblemCode::INVALID_DATE,
            default => ProblemCode::INVALID_TYPE,
        };
    }

    private static function type(FormField $field): Type
    {
        $enum = $field->enum;
        $item = match ($field->kind) {
            'file' => new FileType(self::fileTypes($field), $field->nullable && !$field->list),
            'enum' => $enum === null ? new ScalarType('string') : EnumType::of($enum, $field->nullable && !$field->list),
            'instant' => new ScalarType('string', $field->nullable && !$field->list, 'date-time'),
            'date' => new ScalarType('string', $field->nullable && !$field->list, 'date'),
            default => new ScalarType($field->kind, $field->nullable && !$field->list),
        };

        return $field->list ? new ListType($item, $field->nullable) : $item;
    }

    /**
     * @return list<string>
     */
    private static function fileTypes(FormField $field): array
    {
        foreach ($field->constraints as $constraint) {
            if ($constraint instanceof FileTypes) {
                return $constraint->mediaTypes;
            }
        }

        return [];
    }
}
