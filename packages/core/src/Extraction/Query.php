<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Attribute;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Internal\ScalarConverter;
use Shirakaba\Core\Internal\ScalarResolver;

/**
 * Fills a parameter from a query string parameter: `#[Query] int $page = 1`.
 *
 * The raw query string is parsed here instead of using PHP's parse_str(), which renames
 * keys ("a.b" becomes "a_b") and keeps only the last of repeated keys. A repeated key is
 * a 400. "+" decodes to a space. A missing key uses the parameter's default. Constraint
 * attributes on the parameter are applied after conversion.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Query implements ParameterExtractor, DescribesParameter
{
    /**
     * @param string|null $name the query key; defaults to the handler parameter's name
     */
    public function __construct(
        public ?string $name = null,
    ) {}

    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        return new ScalarResolver('query', $this->name ?? $parameter->name(), ScalarConverter::for($parameter, 'Query'));
    }

    public function describe(HandlerParameter $parameter): ParameterDescription
    {
        return ScalarConverter::for($parameter, 'Query')
            ->describe(ParameterDescription::QUERY, $this->name ?? $parameter->name(), $parameter->name());
    }
}
