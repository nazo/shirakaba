<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests\Conformance;

use Http\Psr7Test\RequestIntegrationTest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Shirakaba\Http\Message\Message;
use Shirakaba\Http\Message\Request;
use Shirakaba\Http\Message\RequestBase;

#[CoversClass(Request::class)]
#[CoversClass(RequestBase::class)]
#[CoversClass(Message::class)]
final class RequestConformanceTest extends RequestIntegrationTest
{
    public function createSubject(): RequestInterface
    {
        return new Request('GET', '/');
    }
}
