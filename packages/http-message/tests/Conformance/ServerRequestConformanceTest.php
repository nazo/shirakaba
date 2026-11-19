<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests\Conformance;

use Http\Psr7Test\ServerRequestIntegrationTest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Http\Message\ServerRequest;

#[CoversClass(ServerRequest::class)]
final class ServerRequestConformanceTest extends ServerRequestIntegrationTest
{
    public function createSubject(): ServerRequestInterface
    {
        return new ServerRequest('GET', '/', serverParams: $_SERVER);
    }
}
