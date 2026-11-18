<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Shirakaba\Core\InvalidApplication;

/**
 * An attribute that fills one handler parameter (design/04-routing-and-handlers.md).
 *
 * plan() runs once while the application is built: it validates the parameter and returns
 * the resolver used for every request. Implement this interface on an attribute class
 * targeting parameters to add an extractor:
 *
 *     #[Attribute(Attribute::TARGET_PARAMETER)]
 *     final readonly class Locale implements ParameterExtractor { ... }
 *
 * Extractors live in application scope: they and their resolvers must not keep request
 * data in properties (design/03-runtimes-and-scopes.md).
 */
interface ParameterExtractor
{
    /**
     * @throws InvalidApplication when the parameter cannot be filled by this extractor
     */
    public function plan(HandlerParameter $parameter): ArgumentResolver;
}
