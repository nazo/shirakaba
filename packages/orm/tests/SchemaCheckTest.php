<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Orm\SchemaCheck;
use Shirakaba\Orm\Tests\Fixtures\Author;
use Shirakaba\Orm\Tests\Fixtures\Comment;
use Shirakaba\Orm\Tests\Fixtures\Post;
use Shirakaba\Orm\Tests\Fixtures\Schema;
use Shirakaba\Orm\Tests\Fixtures\Tag;

#[CoversClass(SchemaCheck::class)]
final class SchemaCheckTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    #[DataProvider('databases')]
    public function testReportsMismatches(Database $database): void
    {
        $db = Schema::create($database)->writer();

        self::assertSame([], SchemaCheck::entities([Author::class, Post::class, Comment::class, Tag::class], $db));

        $db->execute('DROP TABLE orm_post_tags');
        $db->execute('DROP TABLE orm_tags');
        $db->execute('CREATE TABLE orm_tags (id INTEGER PRIMARY KEY, label VARCHAR(10) NULL)');
        self::assertSame(
            ['Tag: table "orm_tags" has no column "name" for $name.'],
            SchemaCheck::entities([Tag::class], $db),
        );
        $db->execute('DROP TABLE orm_tags');
        $db->execute('CREATE TABLE orm_tags (id INTEGER PRIMARY KEY, name VARCHAR(10) NULL)');
        self::assertSame(
            ['Tag: column "name" allows NULL, but $name is not nullable.'],
            SchemaCheck::entities([Tag::class], $db),
        );
        $db->execute('DROP TABLE orm_tags');
        self::assertSame(['Tag: table "orm_tags" does not exist.'], SchemaCheck::entities([Tag::class], $db));
    }
}
