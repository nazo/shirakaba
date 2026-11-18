<?php

declare(strict_types=1);

namespace App\Tests\Eval;

use App\Tests\TestApp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shirakaba\Orm\SchemaCheck;

#[CoversNothing]
final class NotesEvalTest extends TestCase
{
    public function testListsNotesNewestFirstWithAuthors(): void
    {
        $app = TestApp::create();
        $db = $app->database->connect()->writer();
        $db->execute("INSERT INTO users (name, email) VALUES ('Ada', 'ada@example.com'), ('Bob', 'bob@example.com')");
        $db->execute("INSERT INTO notes (author_id, title, created_at) VALUES (1, 'First', '2026-11-01T00:00:00.000000Z'), (2, 'Second', '2026-11-02T00:00:00.000000Z'), (1, 'Third', '2026-11-03T00:00:00.000000Z')");

        $response = $app->client->get('/notes');
        self::assertSame(200, $response->getStatusCode());
        $notes = json_decode((string) $response->getBody(), true);
        self::assertIsArray($notes);
        self::assertSame(
            [['Third', 'Ada'], ['Second', 'Bob'], ['First', 'Ada']],
            array_map(static fn (mixed $note): array => is_array($note) ? [$note['title'] ?? null, $note['author'] ?? null] : [], $notes),
        );
        self::assertSame([], SchemaCheck::entities(\App\Schema::entities(), $db), 'The mappings match the migrated tables.');
    }
}
