<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Migrations\Migration;
use Shirakaba\Migrations\Migrator;
use Shirakaba\Orm\SchemaCheck;
use Shirakaba\Orm\SchemaDiff;
use Shirakaba\Orm\Tests\Fixtures\Author;
use Shirakaba\Orm\Tests\Fixtures\Comment;
use Shirakaba\Orm\Tests\Fixtures\Post;
use Shirakaba\Orm\Tests\Fixtures\Tag;

#[CoversClass(SchemaDiff::class)]
final class SchemaDiffTest extends TestCase
{
    private const array ENTITIES = [Post::class, Comment::class, Author::class, Tag::class];

    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    private static function migration(string $source, string $class): Migration
    {
        $file = tempnam(sys_get_temp_dir(), 'shirakaba-diff');
        self::assertIsString($file);
        file_put_contents($file, $source);
        try {
            require $file;
        } finally {
            unlink($file);
        }
        $fqcn = 'Shirakaba\\Orm\\Tests\\Generated\\' . $class;
        $migration = new $fqcn();
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    #[DataProvider('databases')]
    public function testDiffAgainstAnEmptyDatabaseCreatesEverything(Database $database): void
    {
        $connection = $database->connect();
        $db = $connection->writer();
        foreach (['orm_post_tags', 'orm_comments', 'orm_tags', 'orm_posts', 'orm_authors', 'shirakaba_migrations'] as $table) {
            $db->execute('DROP TABLE IF EXISTS ' . $table);
        }

        $class = 'CreateBlog' . bin2hex(random_bytes(4));
        $source = SchemaDiff::migration(self::ENTITIES, $db, $class, 'Shirakaba\Orm\Tests\Generated');
        self::assertNotNull($source);
        self::assertStringContainsString("\$table->foreign('author_id')->references('orm_authors', 'id');", $source);
        self::assertLessThan(strpos($source, "create('orm_posts'"), strpos($source, "create('orm_authors'"), 'Referenced tables come first.');
        self::assertStringContainsString(
            "create('orm_posts', static function (Blueprint \$table): void {\n            \$table->id();",
            $source,
            'The key comes first.',
        );
        self::assertStringContainsString("\$table->instant('created_at');", $source, 'Timestamps the ORM always writes are NOT NULL.');

        new Migrator($connection, ['001' => self::migration($source, $class)])->migrate();

        self::assertSame([], SchemaCheck::entities(self::ENTITIES, $db));
        self::assertNull(SchemaDiff::migration(self::ENTITIES, $db, 'Nothing'), 'A second diff finds nothing.');

        $db->execute('DROP TABLE orm_post_tags');
        $db->execute('DROP TABLE orm_tags');
        $db->execute('CREATE TABLE orm_tags (id INTEGER PRIMARY KEY)');
        $alter = SchemaDiff::migration([Tag::class], $db, 'AddName');
        self::assertNotNull($alter);
        self::assertStringContainsString(
            "\$schema->alter('orm_tags', static function (Blueprint \$table): void {\n            \$table->string('name');",
            $alter,
        );
    }
}
