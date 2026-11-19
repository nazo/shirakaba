<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Attribute;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Internal\ScalarConverter;
use Shirakaba\Core\Internal\ScalarResolver;

/**
 * Fills a parameter from a request header: `#[Header('X-Request-Id')] string $requestId`.
 *
 * A header with more than one field value is a 400. A missing header uses the parameter's
 * default. Constraint attributes on the parameter are applied after conversion.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Header implements ParameterExtractor, DescribesParameter
{
    public function __construct(
        public string $name,
    ) {}

    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        return new ScalarResolver('header', $this->name, ScalarConverter::for($parameter, 'Header'));
    }

    public function describe(HandlerParameter $parameter): ParameterDescription
    {
        return ScalarConverter::for($parameter, 'Header')
            ->describe(ParameterDescription::HEADER, $this->name, $parameter->name());
    }
}
