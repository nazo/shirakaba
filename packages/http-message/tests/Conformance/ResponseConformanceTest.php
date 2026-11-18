<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests\Conformance;

use Http\Psr7Test\ResponseIntegrationTest;
use PHPUnit\Framework\Attributes\CoversClass;
use Shirakaba\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Response::class)]
final class ResponseConformanceTest extends ResponseIntegrationTest
{
    public function createSubject(): ResponseInterface
    {
        return new Response();
    }
}
