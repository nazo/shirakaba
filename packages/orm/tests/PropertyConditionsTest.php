<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\EntityQuery;
use Shirakaba\Orm\PropertyConditions;
use Shirakaba\Orm\Tests\Fixtures\Author;
use Shirakaba\Orm\Tests\Fixtures\AuthorId;
use Shirakaba\Orm\Tests\Fixtures\Post;
use Shirakaba\Orm\Tests\Fixtures\PostStatus;
use Shirakaba\Orm\Tests\Fixtures\Schema;

#[CoversClass(PropertyConditions::class)]
#[CoversClass(EntityQuery::class)]
final class PropertyConditionsTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    /**
     * @param list<Post> $posts
     * @return list<string>
     */
    private static function titles(array $posts): array
    {
        return array_map(static fn (Post $post): string => $post->title, $posts);
    }

    #[DataProvider('databases')]
    public function testGroupsConditionsOnPropertiesWithOr(Database $database): void
    {
        $db = Schema::create($database)->writer();
        $ada = new AuthorId(Entities::insert(new Author('Ada', 'ada@example.com'), $db)->id ?? 0);
        $bob = new AuthorId(Entities::insert(new Author('Bob', 'bob@example.com'), $db)->id ?? 0);
        $cy = new AuthorId(Entities::insert(new Author('Cy', 'cy@example.com'), $db)->id ?? 0);
        Entities::insertAll([
            new Post($ada, 'Ada draft'),
            new Post($bob, 'Bob published', PostStatus::Published),
            new Post($cy, 'Cy draft'),
            new Post($cy, 'Cy published', PostStatus::Published),
        ], $db);

        // Own posts, or published posts of the followed: value objects and enums are converted.
        $timeline = Entities::query(Post::class)
            ->whereGroup(static fn (PropertyConditions $c): PropertyConditions => $c
                ->where('authorId', '=', $ada)
                ->orWhereGroup(
                    static fn (PropertyConditions $g): PropertyConditions => $g
                        ->whereIn('authorId', [$bob, $cy])
                        ->where('status', '=', PostStatus::Published),
                ))
            ->orderBy('id')
            ->fetch($db);
        self::assertSame(['Ada draft', 'Bob published', 'Cy published'], self::titles($timeline));

        // The group is joined with AND to the rest of the query.
        $published = Entities::query(Post::class)
            ->where('status', '=', PostStatus::Published)
            ->whereGroup(static fn (PropertyConditions $c): PropertyConditions => $c->where('authorId', '=', $ada)->orWhere('authorId', '=', $bob))
            ->fetch($db);
        self::assertSame(['Bob published'], self::titles($published));

        // Negated lists, of values and of a subquery.
        self::assertSame(['Bob published'], self::titles(Entities::query(Post::class)->whereNotIn('authorId', [$ada, $cy])->fetch($db)));
        $cyOnly = \Shirakaba\Query\Select::from('orm_authors')->columns('id')->where('name', '=', 'Cy');
        self::assertSame(
            ['Ada draft', 'Bob published'],
            self::titles(Entities::query(Post::class)->whereNotIn('authorId', $cyOnly)->orderBy('id')->fetch($db)),
        );
        self::assertCount(4, Entities::query(Post::class)->whereNotIn('authorId', [])->fetch($db));
        self::assertSame(
            ['Ada draft', 'Bob published', 'Cy published'],
            self::titles(
                Entities::query(Post::class)
                    ->whereGroup(static fn (PropertyConditions $c): PropertyConditions => $c->where(
                        'status',
                        '=',
                        PostStatus::Published,
                    )->orWhereNotIn('authorId', [$cy]))
                    ->orderBy('id')
                    ->fetch($db),
            ),
        );

        $undated = Entities::query(Post::class)
            ->whereGroup(static fn (PropertyConditions $c): PropertyConditions => $c->whereNull('publishedOn')->orWhereIn('authorId', []))
            ->count($db);
        self::assertSame(4, $undated);
    }
}
