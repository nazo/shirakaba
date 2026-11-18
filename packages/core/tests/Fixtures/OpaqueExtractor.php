<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests\Fixtures;

use Attribute;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\Extraction\ParameterExtractor;

/**
 * A third-party extractor that does not describe itself.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class OpaqueExtractor implements ParameterExtractor
{
    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        return new class implements ArgumentResolver {
            public function resolve(Exchange $exchange): mixed
            {
                return 'opaque';
            }
        };
    }
}
