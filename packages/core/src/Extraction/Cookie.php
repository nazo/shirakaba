<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Attribute;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Internal\ScalarConverter;
use Shirakaba\Core\Internal\ScalarResolver;

/**
 * Fills a parameter from a request cookie: `#[Cookie('theme')] string $theme = 'light'`.
 *
 * Reads the server request's cookie parameters, or the Cookie header when a runtime did
 * not fill them. Constraint attributes on the parameter are applied after conversion.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Cookie implements ParameterExtractor, DescribesParameter
{
    public const string SOURCE = 'cookie';

    public function __construct(
        public string $name,
    ) {}

    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        return new ScalarResolver('cookie', $this->name, ScalarConverter::for($parameter, 'Cookie'));
    }

    public function describe(HandlerParameter $parameter): ParameterDescription
    {
        return ScalarConverter::for($parameter, 'Cookie')->describe(self::SOURCE, $this->name, $parameter->name());
    }
}
