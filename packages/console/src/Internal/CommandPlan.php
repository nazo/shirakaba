<?php

declare(strict_types=1);

namespace Shirakaba\Console\Internal;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use Shirakaba\Console\Argument;
use Shirakaba\Console\Option;
use Shirakaba\Console\Output;
use Shirakaba\Console\UsageError;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Time\LocalDate;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * A command planned when the console is built: its parameters and how to parse its part of
 * the command line.
 *
 * @internal
 */
final readonly class CommandPlan
{
    /** @var list<string> */
    private const array RESERVED = ['help', 'verbose'];

    /**
     * @param list<Parameter> $parameters
     */
    private function __construct(
        public string $name,
        public string $description,
        public Closure $handler,
        public array $parameters,
    ) {}

    /**
     * @throws InvalidApplication when a parameter cannot be filled from the command line
     */
    public static function of(string $name, string $description, Closure $handler): self
    {
        $parameters = [];
        $optionNames = [];
        $optionalSeen = false;
        foreach (new ReflectionFunction($handler)->getParameters() as $parameter) {
            $plan = self::parameter($name, $parameter);
            if ($plan->source === 'option') {
                if (in_array($plan->cliName, self::RESERVED, true) || isset($optionNames[$plan->cliName]) || ($plan->short !== null && isset($optionNames[$plan->short]))) {
                    throw new InvalidApplication(sprintf('Command "%s": option --%s is reserved or declared twice.', $name, $plan->cliName));
                }
                $optionNames[$plan->cliName] = true;
                if ($plan->short !== null) {
                    $optionNames[$plan->short] = true;
                }
            }
            if ($plan->source === 'argument') {
                if ($optionalSeen && !$plan->hasDefault && !$plan->list) {
                    throw new InvalidApplication(sprintf('Command "%s": required argument <%s> follows an optional one.', $name, $plan->cliName));
                }
                $optionalSeen = $optionalSeen || $plan->hasDefault || $plan->list;
            }
            $parameters[] = $plan;
        }

        return new self($name, $description, $handler, $parameters);
    }

    /**
     * Parses the command's arguments into handler arguments.
     *
     * @param list<string> $tokens
     * @return list<mixed>
     * @throws UsageError
     */
    public function arguments(array $tokens, Output $output, Cancellation $cancellation): array
    {
        $options = [];
        $positional = [];
        $byName = [];
        foreach ($this->parameters as $parameter) {
            if ($parameter->source === 'option') {
                $byName[$parameter->cliName] = $parameter;
                if ($parameter->short !== null) {
                    $byName[$parameter->short] = $parameter;
                }
            }
        }
        $onlyArguments = false;
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($onlyArguments || $token === '-' || !str_starts_with($token, '-')) {
                $positional[] = $token;
                continue;
            }
            if ($token === '--') {
                $onlyArguments = true;
                continue;
            }
            $long = str_starts_with($token, '--');
            $body = substr($token, $long ? 2 : 1);
            $value = null;
            $equals = strpos($body, '=');
            if ($equals !== false) {
                $value = substr($body, $equals + 1);
                $body = substr($body, 0, $equals);
            }
            $negated = false;
            $parameter = $byName[$body] ?? null;
            if ($parameter === null && $long && str_starts_with($body, 'no-')) {
                $parameter = $byName[substr($body, 3)] ?? null;
                $negated = $parameter !== null && $parameter->type === 'bool' && $value === null;
                if (!$negated) {
                    $parameter = null;
                }
            }
            if ($parameter === null || (!$long && $parameter->short !== $body)) {
                throw new UsageError(sprintf('Unknown option %s.', $token));
            }
            if ($parameter->type === 'bool' && !$parameter->list && $value === null) {
                $options[$parameter->name] = !$negated;
                continue;
            }
            if ($value === null) {
                $value = $tokens[++$index] ?? throw new UsageError(sprintf('Option --%s needs a value.', $parameter->cliName));
            }
            $converted = self::convert($parameter, $value, '--' . $parameter->cliName);
            if ($parameter->list) {
                $existing = $options[$parameter->name] ?? [];
                $options[$parameter->name] = [...(is_array($existing) ? $existing : []), $converted];
            } else {
                $options[$parameter->name] = $converted;
            }
        }

        $arguments = [];
        foreach ($this->parameters as $parameter) {
            $arguments[] = match ($parameter->source) {
                'output' => $output,
                'cancellation' => $cancellation,
                'option' => array_key_exists($parameter->name, $options)
                    ? $options[$parameter->name]
                    : ($parameter->hasDefault ? $parameter->default : ($parameter->list ? [] : throw new UsageError(sprintf('Option --%s is required.', $parameter->cliName)))),
                'argument' => self::positional($parameter, $positional),
            };
            if ($parameter->source === 'argument' && $parameter->list) {
                // A variadic argument is spread, so it must be the last value.
                $last = array_pop($arguments);

                return [...$arguments, ...(is_array($last) ? array_values($last) : [])];
            }
        }
        if ($positional !== []) {
            throw new UsageError(sprintf('Unexpected argument "%s".', $positional[0]));
        }

        return $arguments;
    }

    public function usage(string $application): string
    {
        $line = sprintf('Usage: %s %s', $application, $this->name);
        $arguments = [];
        $options = [];
        foreach ($this->parameters as $parameter) {
            if ($parameter->source === 'argument') {
                $line .= ' ' . ($parameter->list ? sprintf('[<%s>...]', $parameter->cliName) : ($parameter->hasDefault ? sprintf('[<%s>]', $parameter->cliName) : sprintf('<%s>', $parameter->cliName)));
                $arguments[] = [$parameter->cliName, self::describe($parameter)];
            }
            if ($parameter->source === 'option') {
                $flag = '--' . $parameter->cliName . ($parameter->type === 'bool' && !$parameter->list ? '' : '=<' . $parameter->cliName . '>');
                $options[] = [($parameter->short !== null ? '-' . $parameter->short . ', ' : '') . $flag, self::describe($parameter)];
            }
        }
        if ($options !== []) {
            $line .= ' [options]';
        }
        $text = $line . "\n";
        if ($this->description !== '') {
            $text .= "\n" . $this->description . "\n";
        }
        foreach (['Arguments' => $arguments, 'Options' => $options] as $title => $rows) {
            if ($rows === []) {
                continue;
            }
            $width = max(array_map(static fn (array $row): int => strlen($row[0]), $rows));
            $text .= "\n" . $title . ":\n";
            foreach ($rows as [$name, $description]) {
                $text .= rtrim(sprintf('  %-' . $width . 's  %s', $name, $description)) . "\n";
            }
        }

        return $text;
    }

    private static function describe(Parameter $parameter): string
    {
        $details = [];
        if ($parameter->type !== 'string' && $parameter->type !== 'bool') {
            $details[] = $parameter->expectation();
        }
        if ($parameter->hasDefault && $parameter->default !== null && $parameter->default !== false && $parameter->default !== []) {
            $default = $parameter->default instanceof BackedEnum ? $parameter->default->value : $parameter->default;
            $details[] = 'default: ' . (is_scalar($default) ? var_export($default, true) : get_debug_type($default));
        }
        if ($parameter->list && $parameter->source === 'option') {
            $details[] = 'repeatable';
        }

        return trim($parameter->description . ($details === [] ? '' : ' (' . implode('; ', $details) . ')'));
    }

    /**
     * @param list<string> $positional
     */
    private static function positional(Parameter $parameter, array &$positional): mixed
    {
        if ($parameter->list) {
            $values = array_map(static fn (string $value): mixed => self::convert($parameter, $value, '<' . $parameter->cliName . '>'), $positional);
            $positional = [];

            return $values;
        }
        $value = array_shift($positional);
        if ($value === null) {
            return $parameter->hasDefault ? $parameter->default : throw new UsageError(sprintf('Missing argument <%s>.', $parameter->cliName));
        }

        return self::convert($parameter, $value, '<' . $parameter->cliName . '>');
    }

    private static function convert(Parameter $parameter, string $value, string $label): mixed
    {
        return $parameter->convert($value) ?? throw new UsageError(sprintf('%s must be %s.', $label, $parameter->expectation()));
    }

    private static function parameter(string $command, ReflectionParameter $parameter): Parameter
    {
        $type = $parameter->getType();
        $where = sprintf('Command "%s", parameter $%s', $command, $parameter->getName());
        if (!$type instanceof ReflectionNamedType) {
            throw new InvalidApplication($where . ' needs a single declared type.');
        }
        $typeName = $type->getName();
        if ($typeName === Output::class) {
            return new Parameter($parameter->getName(), 'output');
        }
        if ($typeName === Cancellation::class) {
            return new Parameter($parameter->getName(), 'cancellation');
        }
        $argument = ($parameter->getAttributes(Argument::class)[0] ?? null)?->newInstance();
        $option = ($parameter->getAttributes(Option::class)[0] ?? null)?->newInstance();
        if (($argument === null) === ($option === null)) {
            throw new InvalidApplication($where . ' needs #[Argument] or #[Option] (or the type Output or Cancellation).');
        }
        $list = $parameter->isVariadic() || $typeName === 'array';
        if ($typeName === 'array' && $argument !== null) {
            throw new InvalidApplication($where . ': a list argument is declared as variadic (string ...$files).');
        }
        $itemType = $typeName === 'array' ? 'string' : $typeName;
        if (!in_array($itemType, ['string', 'int', 'float', 'bool', DateTimeImmutable::class, LocalDate::class], true)
            && !(enum_exists($itemType) && is_subclass_of($itemType, BackedEnum::class))) {
            throw new InvalidApplication(sprintf('%s has the unsupported type %s; use string, int, float, bool, a backed enum, DateTimeImmutable, LocalDate or a list.', $where, $typeName));
        }
        $hasDefault = $parameter->isDefaultValueAvailable();
        $default = $hasDefault ? $parameter->getDefaultValue() : null;
        if ($option !== null && $itemType === 'bool' && !$list && !$hasDefault) {
            $hasDefault = true;
            $default = false;
        }
        $kebab = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', $parameter->getName()));

        return new Parameter(
            $parameter->getName(),
            $argument !== null ? 'argument' : 'option',
            $itemType,
            $option->name ?? $kebab,
            $option?->short,
            $hasDefault || ($argument === null && $type->allowsNull()),
            $default,
            $list,
            $argument->description ?? $option->description ?? '',
        );
    }
}
