<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\EntityNotFound;
use Shirakaba\Orm\EntityQuery;
use Shirakaba\Orm\Mapping\EntityMap;
use Shirakaba\Orm\Mapping\Loader;
use Shirakaba\Orm\Mapping\Projection;
use Shirakaba\Orm\StaleEntity;
use Shirakaba\Orm\Tests\Fixtures\Address;
use Shirakaba\Orm\Tests\Fixtures\Author;
use Shirakaba\Orm\Tests\Fixtures\AuthorId;
use Shirakaba\Orm\Tests\Fixtures\Comment;
use Shirakaba\Orm\Tests\Fixtures\Post;
use Shirakaba\Orm\Tests\Fixtures\PostCard;
use Shirakaba\Orm\Tests\Fixtures\PostStatus;
use Shirakaba\Orm\Tests\Fixtures\PostTitle;
use Shirakaba\Orm\Tests\Fixtures\PostView;
use Shirakaba\Orm\Tests\Fixtures\Schema;
use Shirakaba\Orm\Tests\Fixtures\Tag;
use Shirakaba\Query\Insert;
use Shirakaba\Time\FrozenClock;
use Shirakaba\Time\LocalDate;

#[CoversClass(Entities::class)]
#[CoversClass(EntityQuery::class)]
#[CoversClass(EntityMap::class)]
#[CoversClass(Loader::class)]
#[CoversClass(Projection::class)]
final class OrmTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    private static function statements(Connection $connection): int
    {
        return $connection->statistics()[0];
    }

    #[DataProvider('databases')]
    public function testWritesAndReadsEntities(Database $database): void
    {
        $connection = Schema::create($database);
        $db = $connection->writer();
        $clock = new FrozenClock('2026-11-11T09:00:00.25+09:00');

        $settings = new \Shirakaba\Orm\Tests\Fixtures\AuthorSettings(true, ['en', 'ja'], \Shirakaba\Time\LocalDate::of(2026, 12, 31));
        $author = Entities::insert(new Author('Ada', 'ada@example.com', new Address('London', 'N1'), $settings), $db);
        self::assertSame(1, $author->id);
        $post = Entities::insert(new Post(new AuthorId(1), 'Hello', PostStatus::Published, LocalDate::of(2026, 11, 11), ['tags' => ['php'], 'n' => 1.0]), $db, $clock);

        self::assertSame(1, $post->id);
        self::assertSame(1, $post->version);
        self::assertSame('2026-11-11T00:00:00.250000+00:00', $post->createdAt?->format('Y-m-d\TH:i:s.uP'));

        $read = Entities::get(Post::class, 1, $db);
        self::assertEquals($post, $read);
        self::assertEquals(new Address('London', 'N1'), Entities::get(Author::class, 1, $db)->address);
        self::assertEquals($settings, Entities::get(Author::class, 1, $db)->settings, 'A #[Json] DTO round-trips through shirakaba/json.');
        self::assertNull(Entities::find(Post::class, 99, $db));

        $clock->advance(60);
        $updated = Entities::update(Entities::change($read, ['title' => 'Hello, world']), $db, $clock);
        self::assertSame(2, $updated->version);
        self::assertSame('2026-11-11T00:01:00.250000+00:00', $updated->updatedAt?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('Hello, world', Entities::get(Post::class, 1, $db)->title);

        try {
            Entities::update(Entities::change($read, ['title' => 'Stale']), $db, $clock);
            self::fail('Expected a stale entity.');
        } catch (StaleEntity $e) {
            self::assertSame('The post was changed by someone else; reload it and try again.', $e->getMessage(), 'Errors use the #[Table] label, not the class.');
        }

        self::assertSame(1, Entities::patch(Post::class, 1, ['status' => PostStatus::Draft], $db, $clock));
        $patched = Entities::get(Post::class, 1, $db);
        self::assertSame(PostStatus::Draft, $patched->status);
        self::assertSame(3, $patched->version, 'patch() increments the version too.');

        self::assertSame(1, Entities::query(Post::class)->where('authorId', '=', new AuthorId(1))->where('publishedOn', '=', LocalDate::of(2026, 11, 11))->count($db));
        self::assertSame(['php'], Entities::query(Post::class)->first($db)?->meta['tags'] ?? null);

        self::assertTrue(Entities::delete($patched, $db));
        try {
            Entities::get(Author::class, 99, $db);
            self::fail('Expected EntityNotFound.');
        } catch (EntityNotFound $e) {
            self::assertSame('The requested resource was not found.', $e->getMessage(), 'Without a label the class name stays internal.');
        }
        $this->expectException(EntityNotFound::class);
        Entities::get(Post::class, 1, $db);
    }

    #[DataProvider('databases')]
    public function testLoadsRelationsIntoProjectionsWithOneQueryPerLevel(Database $database): void
    {
        $connection = Schema::create($database);
        $db = $connection->writer();
        $ada = Entities::insert(new Author('Ada', 'ada@example.com'), $db);
        $bob = Entities::insert(new Author('Bob', 'bob@example.com'), $db);
        $adaId = new AuthorId($ada->id ?? 0);
        $bobId = new AuthorId($bob->id ?? 0);
        $first = Entities::insert(new Post($adaId, 'First'), $db);
        $second = Entities::insert(new Post($adaId, 'Second'), $db);
        Entities::insert(new Post($bobId, 'Third'), $db);
        Entities::insertAll([
            new Comment($first->id ?? 0, $bobId, 'Nice'),
            new Comment($first->id ?? 0, $adaId, 'Thanks', approved: true),
            new Comment($second->id ?? 0, $bobId, 'Hm'),
        ], $db);
        $php = Entities::insert(new Tag('php'), $db);
        $sql = Entities::insert(new Tag('sql'), $db);
        Insert::into('orm_post_tags')->rows([
            ['post_id' => $first->id, 'tag_id' => $php->id, 'position' => 2],
            ['post_id' => $first->id, 'tag_id' => $sql->id, 'position' => 1],
            ['post_id' => $second->id, 'tag_id' => $sql->id, 'position' => 1],
        ])->execute($db);

        $before = self::statements($connection);
        $views = Entities::query(Post::class)
            ->with('author', 'comments.author', 'tags')
            ->orderBy('id')
            ->into(PostView::class, $db);

        // Posts, comments, join rows, tags, and one statement for post and comment authors.
        self::assertSame(5, self::statements($connection) - $before);
        self::assertSame(['First', 'Second', 'Third'], array_map(static fn (PostView $view): string => $view->post->title, $views));
        self::assertSame(['Ada', 'Ada', 'Bob'], array_map(static fn (PostView $view): string => $view->author->name, $views));
        $firstView = $views[0] ?? null;
        self::assertNotNull($firstView);
        self::assertSame([['Nice', 'Bob'], ['Thanks', 'Ada']], array_map(static fn ($c): array => [$c->comment->body, $c->author->name], $firstView->comments));
        self::assertSame(['sql', 'php'], array_map(static fn (Tag $tag): string => $tag->name, $firstView->tags), 'Ordered by the join table position.');
        self::assertSame([], ($views[2] ?? null)?->comments);

        self::assertSame(['First'], array_map(
            static fn (Post $post): string => $post->title,
            Entities::query(Post::class)->whereHas('comments', static fn (EntityQuery $c): EntityQuery => $c->where('approved', '=', true))->fetch($db),
        ));
        self::assertSame(['Third'], array_map(static fn (Post $post): string => $post->title, Entities::query(Post::class)->whereDoesntHave('comments')->fetch($db)));
        self::assertSame(['First', 'Second'], array_map(static fn (Post $post): string => $post->title, Entities::query(Post::class)->whereHas('tags', static fn (EntityQuery $t): EntityQuery => $t->where('name', '=', 'sql'))->orderBy('id')->fetch($db)));

        $before = self::statements($connection);
        $summaries = Entities::query(Post::class)
            ->withWhere('comments', static fn (EntityQuery $c): EntityQuery => $c->where('approved', '=', true))
            ->withCount('comments', 'tags')
            ->orderBy('id')
            ->into(\Shirakaba\Orm\Tests\Fixtures\PostSummary::class, $db);
        self::assertSame(4, self::statements($connection) - $before, 'Posts, filtered comments, and one grouped count per relation.');
        self::assertSame(
            [['First', ['Thanks'], 2, 2], ['Second', [], 1, 1], ['Third', [], 0, 0]],
            array_map(static fn ($s): array => [$s->post->title, array_map(static fn (Comment $c): string => $c->body, $s->comments), $s->commentsCount, $s->tagsCount], $summaries),
        );

        $before = self::statements($connection);
        $comments = Entities::query(Comment::class)
            ->with('author', 'post.author')
            ->withWhere('post', static fn (EntityQuery $p): EntityQuery => $p->orderBy('id'))
            ->orderBy('id')
            ->into(\Shirakaba\Orm\Tests\Fixtures\CommentThread::class, $db);
        self::assertSame(3, self::statements($connection) - $before, 'Comments, posts, and authors of both paths in one statement.');
        self::assertSame(
            [['Nice', 'Bob', 'Ada'], ['Thanks', 'Ada', 'Ada'], ['Hm', 'Bob', 'Ada']],
            array_map(static fn ($c): array => [$c->comment->body, $c->author->name, $c->post->author->name], $comments),
        );

        $before = self::statements($connection);
        Entities::query(Comment::class)
            ->with('author', 'post')
            ->withWhere('author', static fn (EntityQuery $a): EntityQuery => $a->where('name', '=', 'Ada'))
            ->into(\Shirakaba\Orm\Tests\Fixtures\CommentWithPost::class, $db);
        self::assertSame(3, self::statements($connection) - $before, 'A filtered relation keeps its own statement.');

        $before = self::statements($connection);
        $tagged = Entities::query(Post::class)
            ->with('tags', 'comments.post.tags')
            ->orderBy('id')
            ->into(\Shirakaba\Orm\Tests\Fixtures\TaggedPost::class, $db);
        self::assertSame(5, self::statements($connection) - $before, 'Posts, comments, their posts, one join-table read and one tag read for both paths.');
        $firstTagged = $tagged[0] ?? self::fail('No post was read.');
        self::assertSame(['sql', 'php'], array_map(static fn (Tag $tag): string => $tag->name, $firstTagged->tags));
        self::assertSame(
            [['Nice', ['sql', 'php']], ['Thanks', ['sql', 'php']]],
            array_map(static fn ($c): array => [$c->comment->body, array_map(static fn (Tag $tag): string => $tag->name, $c->post->tags)], $firstTagged->comments),
        );
        self::assertSame(['sql'], array_map(static fn (Tag $tag): string => $tag->name, ($tagged[1] ?? self::fail('No second post.'))->tags));
        self::assertSame([], ($tagged[2] ?? self::fail('No third post.'))->tags);

        $authors = Entities::load([$first, $second], 'author', $db);
        self::assertSame(['Ada', 'Ada'], array_map(static fn (mixed $author): string => $author instanceof Author ? $author->name : '-', $authors));
    }

    #[DataProvider('databases')]
    public function testMapsEntitiesIntoDtosByPropertyName(Database $database): void
    {
        $db = Schema::create($database)->writer();
        $author = Entities::insert(new Author('Ada', 'ada@example.com'), $db);
        $post = Entities::insert(new Post(new AuthorId($author->id ?? 0), 'Hello'), $db, new FrozenClock('2026-11-11T00:00:00Z'));

        $view = Entities::into($post, PostTitle::class);
        self::assertSame([1, 'Hello', PostStatus::Draft, '2026-11-11', 1], [$view->id, $view->title, $view->status, $view->createdAt->format('Y-m-d'), $view->version]);
        self::assertEquals([$view], Entities::query(Post::class)->into(PostTitle::class, $db), 'into() needs no relations.');

        $card = Entities::query(Post::class)->with('author')->into(PostCard::class, $db)[0] ?? self::fail('No post was read.');
        self::assertSame(['Hello', 'Ada'], [$card->title, $card->author->name], 'Fields by name and relations combine.');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('requires "id"');
        Entities::into(new Post(new AuthorId(1), 'Not stored'), PostTitle::class);
    }

    #[DataProvider('databases')]
    public function testBulkUpdatesAndDeletesMaintainManagedColumns(Database $database): void
    {
        $db = Schema::create($database)->writer();
        $author = Entities::insert(new Author('Ada', 'ada@example.com'), $db);
        $authorId = new AuthorId($author->id ?? 0);
        $clock = new FrozenClock('2026-01-01T00:00:00Z');
        Entities::insertAll([new Post($authorId, 'a'), new Post($authorId, 'b'), new Post($authorId, 'c', PostStatus::Published)], $db, $clock);
        $clock->advance(3600);

        $changed = Entities::query(Post::class)->where('status', '=', PostStatus::Draft)->update(['status' => PostStatus::Published], $db, $clock);

        self::assertSame(2, $changed);
        $posts = Entities::query(Post::class)->orderBy('id')->fetch($db);
        self::assertSame([2, 2, 1], array_map(static fn (Post $post): int => $post->version, $posts), 'Versions increase, so stale copies are detected.');
        self::assertSame(['01:00', '01:00', '00:00'], array_map(static fn (Post $post): string => $post->updatedAt?->format('H:i') ?? '-', $posts));

        self::assertSame(1, Entities::query(Post::class)->where('title', '=', 'a')->delete($db));
        self::assertSame(['b', 'c'], array_map(static fn (Post $post): string => $post->title, Entities::query(Post::class)->orderBy('id')->fetch($db)));
    }

    public function testInsertedEntitiesKeepTheirClockTimestamps(): void
    {
        $database = new Database(static fn (): \PDO => new \PDO('sqlite::memory:'));
        $db = Schema::create($database)->writer();
        Entities::insert(new Author('Ada', 'ada@example.com'), $db);
        $post = Entities::insert(new Post(new AuthorId(1), 'x', createdAt: new DateTimeImmutable('2000-01-01T00:00:00Z')), $db, new FrozenClock('2026-01-01T00:00:00Z'));

        self::assertSame('2026-01-01', $post->createdAt?->format('Y-m-d'), '#[CreatedAt] comes from the clock.');
    }
}
