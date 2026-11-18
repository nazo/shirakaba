<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

use Attribute;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\Extraction\ParameterExtractor;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Opaque implements ParameterExtractor
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
