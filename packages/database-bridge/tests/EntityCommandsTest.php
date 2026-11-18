<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Testing\ConsoleTester;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\EntityCommands;
use Shirakaba\DatabaseBridge\Tests\Fixtures\Book;
use Shirakaba\DatabaseBridge\Tests\Fixtures\Shelf;

#[CoversClass(EntityCommands::class)]
final class EntityCommandsTest extends TestCase
{
    private static function tester(): ConsoleTester
    {
        $connection = new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect();
        $writer = $connection->writer();
        $writer->execute('CREATE TABLE bridge_shelves (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $writer->execute('CREATE TABLE bridge_books (id INTEGER PRIMARY KEY AUTOINCREMENT, shelf_id INTEGER NOT NULL REFERENCES bridge_shelves (id), title TEXT NOT NULL)');
        $cli = new ConsoleBuilder('app');
        EntityCommands::register($cli, static fn (): array => [Shelf::class, Book::class], static fn () => $connection->reader());

        return new ConsoleTester($cli->build());
    }

    public function testListsEntities(): void
    {
        $output = self::tester()->run('entities')->output;

        self::assertStringContainsString(Shelf::class . '  bridge_shelves', $output);
        self::assertStringContainsString(Book::class . '   bridge_books', $output);
    }

    public function testShowsAnEntityByShortNameOrTable(): void
    {
        $text = self::tester()->run('entities:show', 'Book')->output;

        self::assertSame($text, self::tester()->run('entities:show', 'bridge_books')->output);
        self::assertStringContainsString("table: bridge_books\n", $text);
        self::assertStringContainsString('  shelfId => shelf_id (int)', $text);
        self::assertStringContainsString('  id => id (int, id, generated, nullable)', $text);
    }

    public function testPrintsJson(): void
    {
        $shelf = json_decode(self::tester()->run('entities:show', Shelf::class, '--json')->output, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($shelf);
        self::assertSame(['class', 'table', 'columns', 'relations', 'defaultScopes', 'requiredScopes', 'softDelete'], array_keys($shelf));
        self::assertIsArray($shelf['relations'] ?? null);
        self::assertContains(
            ['name' => 'books', 'kind' => 'hasMany', 'entity' => Book::class, 'foreignKey' => 'shelfId', 'orderBy' => 'id'],
            $shelf['relations'],
        );
    }

    public function testChecksTheDatabase(): void
    {
        $result = self::tester()->run('entities:check');

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertSame("The database matches every entity.\n", $result->output);
    }

    public function testUnknownEntitiesAreUsageErrors(): void
    {
        self::assertSame(2, self::tester()->run('entities:show', 'Nope')->exitCode);
    }
}
