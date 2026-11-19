<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Attribute;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Internal\ScalarConverter;
use Shirakaba\Core\Internal\ScalarResolver;
use Shirakaba\Core\InvalidApplication;

/**
 * Fills a parameter from a route template parameter: `#[Path] int $id` for "/users/{id}".
 * Constraint attributes on the parameter are applied after conversion.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Path implements ParameterExtractor, DescribesParameter
{
    /**
     * @param string|null $name the template parameter; defaults to the handler parameter's name
     */
    public function __construct(
        public ?string $name = null,
    ) {}

    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        [$name, $converter] = $this->prepare($parameter);

        return new ScalarResolver('path', $name, $converter);
    }

    public function describe(HandlerParameter $parameter): ParameterDescription
    {
        [$name, $converter] = $this->prepare($parameter);
        $description = $converter->describe(ParameterDescription::PATH, $name, $parameter->name());

        // A matched route always has its path parameters, whatever the PHP default says.
        return new ParameterDescription(
            $description->source,
            $description->name,
            $description->parameter,
            $description->type,
            required: true,
            constraints: $description->constraints,
        );
    }

    /**
     * @return array{string, ScalarConverter}
     */
    private function prepare(HandlerParameter $parameter): array
    {
        $name = $this->name ?? $parameter->name();
        if (!in_array($name, $parameter->routeParameters, true)) {
            throw new InvalidApplication(sprintf('%s: the route template has no parameter "{%s}".', $parameter->describe(), $name));
        }

        return [$name, ScalarConverter::for($parameter, 'Path')];
    }
}
