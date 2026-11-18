<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Orm\Attribute\Computed;
use Shirakaba\Orm\Attribute\From;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\EntityQuery;
use Shirakaba\Orm\Mapping\EntityMap;
use Shirakaba\Orm\Mapping\Projection;
use Shirakaba\Orm\MappingError;
use Shirakaba\Orm\Tests\Fixtures\Author;
use Shirakaba\Orm\Tests\Fixtures\AuthorId;
use Shirakaba\Orm\Tests\Fixtures\Comment;
use Shirakaba\Orm\Tests\Fixtures\Post;
use Shirakaba\Orm\Tests\Fixtures\PostDigest;
use Shirakaba\Orm\Tests\Fixtures\PostScore;
use Shirakaba\Orm\Tests\Fixtures\Schema;
use Shirakaba\Orm\Tests\Fixtures\ScoredComment;
use Shirakaba\Orm\Tests\Fixtures\ScoredPost;

#[CoversClass(Projection::class)]
#[CoversClass(EntityQuery::class)]
#[CoversClass(Entities::class)]
#[CoversClass(From::class)]
#[CoversClass(Computed::class)]
final class ProjectionSourcesTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    #[DataProvider('databases')]
    public function testRenamedSourcesAndComputedValues(Database $database): void
    {
        $db = Schema::create($database)->writer();
        $ada = Entities::insert(new Author('Ada', 'ada@example.com'), $db);
        $adaId = new AuthorId($ada->id ?? 0);
        $first = Entities::insert(new Post($adaId, 'Hello world'), $db);
        Entities::insert(new Post($adaId, 'Bye'), $db);
        Entities::insert(new Comment($first->id ?? 0, $adaId, 'Nice'), $db);

        $batches = 0;
        $digests = Entities::query(Post::class)
            ->with('author')
            ->withCount('comments')
            ->orderBy('id')
            ->into(PostDigest::class, $db, static function (array $posts) use (&$batches): array {
                $batches++;

                return array_map(static fn (Post $post): array => ['words' => explode(' ', $post->title)] + ($post->title === 'Bye' ? ['starred' => true] : []), $posts);
            });

        self::assertSame(1, $batches, 'The callback runs once for the whole result.');
        self::assertSame(
            [['Hello world', 'Ada', 1, ['Hello', 'world'], false], ['Bye', 'Ada', 0, ['Bye'], true]],
            array_map(static fn (PostDigest $d): array => [$d->headline, $d->writer->name, $d->comments, $d->words, $d->starred], $digests),
        );

        self::assertEquals(new PostScore('Hello world', 3), Entities::into($first, PostScore::class, ['score' => 3]));
    }

    #[DataProvider('databases')]
    public function testOneCallbackComputesValuesForNestedProjections(Database $database): void
    {
        $db = Schema::create($database)->writer();
        $adaId = new AuthorId(Entities::insert(new Author('Ada', 'ada@example.com'), $db)->id ?? 0);
        $post = Entities::insert(new Post($adaId, 'Hello'), $db);
        Entities::insertAll([new Comment($post->id ?? 0, $adaId, 'Nice'), new Comment($post->id ?? 0, $adaId, 'Thanks!')], $db);

        $calls = 0;
        $views = Entities::query(Post::class)->with('comments')->into(ScoredPost::class, $db, static function (array $posts, array $related) use (&$calls): array {
            $calls++;
            self::assertContainsOnlyInstancesOf(Post::class, $posts);
            self::assertContainsOnlyInstancesOf(Comment::class, $related);

            return [
                ...array_map(static fn (Post $post): array => ['score' => strlen($post->title)], $posts),
                ...array_map(static fn (Comment $comment): array => ['score' => strlen($comment->body), 'mine' => $comment->body === 'Nice'], $related),
            ];
        });

        self::assertSame(1, $calls);
        $view = $views[0] ?? self::fail('No post was read.');
        self::assertSame(5, $view->score);
        self::assertSame([['Nice', 4, true], ['Thanks!', 7, false]], array_map(static fn (ScoredComment $c): array => [$c->comment->body, $c->score, $c->mine], $view->comments));

        $this->expectException(MappingError::class);
        $this->expectExceptionMessage('have no #[Computed] parameter $likes');
        Entities::query(Post::class)->with('comments')->into(ScoredPost::class, $db, static fn (array $posts): array => [['likes' => 1]]);
    }

    public function testComputedValuesMustMatchTheParameters(): void
    {
        $post = new Post(new AuthorId(1), 'Hello', id: 1);

        try {
            Entities::into($post, PostScore::class);
            self::fail('A computed parameter without a default needs a value.');
        } catch (MappingError $e) {
            self::assertStringContainsString('needs a value for the #[Computed] parameter $score', $e->getMessage());
        }

        $this->expectException(MappingError::class);
        $this->expectExceptionMessage('has no #[Computed] parameter $name');
        Entities::into($post, PostScore::class, ['score' => 1, 'name' => 'other']);
    }

    public function testFromMustNameASource(): void
    {
        $projection = new class ('') {
            public function __construct(#[From('headline')] public string $title) {}
        };

        $this->expectException(MappingError::class);
        $this->expectExceptionMessage("is #[From('headline')], which is neither a property of " . Post::class);
        Projection::plan($projection::class, EntityMap::of(Post::class), []);
    }

    public function testAnUnknownParameterSuggestsComputed(): void
    {
        $projection = new class (0) {
            public function __construct(public int $score) {}
        };

        $this->expectException(MappingError::class);
        $this->expectExceptionMessage('mark values computed elsewhere #[Computed]');
        Projection::plan($projection::class, EntityMap::of(Post::class), []);
    }
}
