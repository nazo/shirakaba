<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HealthTest extends TestCase
{
    public function testHealth(): void
    {
        $response = TestApp::create()->client->get('/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
    }
}
