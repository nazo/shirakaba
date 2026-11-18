<?php

declare(strict_types=1);

namespace App\Tests\Eval;

use App\Tests\TestApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shirakaba\Queue\JobRunner;

#[CoversNothing]
final class WelcomeEvalTest extends TestCase
{
    public function testSignUpQueuesAnIdempotentWelcomeMail(): void
    {
        $app = TestApp::create();
        $response = $app->client->post('/users', '{"name":"Ada","email":"ada@example.com"}', ['Content-Type' => 'application/json']);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $app->queue->pendingTypes(), 'One job was queued.');
        self::assertSame(1, new JobRunner($app->jobs)->runPending());

        $reader = $app->database->connect()->reader();
        self::assertEquals(1, $reader->fetchValue("SELECT COUNT(*) FROM sent_mails WHERE kind = 'welcome'"));
        self::assertSame([], $app->queue->failures());
    }

    public function testRejectsInvalidSignUps(): void
    {
        $app = TestApp::create();

        self::assertSame(422, $app->client->post('/users', '{"name":"","email":"nope"}', ['Content-Type' => 'application/json'])->getStatusCode());
        self::assertSame([], $app->queue->pendingTypes());
    }
}
