<?php

declare(strict_types=1);

namespace App\Tests\Eval;

use App\Tests\TestApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class UserEvalTest extends TestCase
{
    public function testShowsUsersAndAnswers404(): void
    {
        $app = TestApp::create();
        $app->database->connect()->writer()->execute("INSERT INTO users (name, email) VALUES ('Ada', 'ada@example.com')");

        $found = $app->client->get('/users/1');
        self::assertSame(200, $found->getStatusCode());
        self::assertSame(['id' => 1, 'name' => 'Ada'], json_decode((string) $found->getBody(), true));
        self::assertSame(404, $app->client->get('/users/999')->getStatusCode());
    }
}
