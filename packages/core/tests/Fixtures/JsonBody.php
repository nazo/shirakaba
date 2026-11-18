<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests\Fixtures;

use Attribute;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\BodyExtractor;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Core\Extraction\HandlerParameter;

/**
 * A minimal body extractor standing in for a JSON package: yields the raw body.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class JsonBody implements BodyExtractor
{
    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        return new class implements ArgumentResolver {
            public function resolve(Exchange $exchange): mixed
            {
                return (string) $exchange->request->getBody();
            }
        };
    }
}
