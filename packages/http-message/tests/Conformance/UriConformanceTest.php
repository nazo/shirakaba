<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests\Conformance;

use Http\Psr7Test\UriIntegrationTest;
use PHPUnit\Framework\Attributes\CoversClass;
use Shirakaba\Http\Message\Uri;
use Psr\Http\Message\UriInterface;

#[CoversClass(Uri::class)]
final class UriConformanceTest extends UriIntegrationTest
{
    /**
     * @param string $uri
     */
    public function createUri($uri): UriInterface
    {
        return new Uri($uri);
    }
}
