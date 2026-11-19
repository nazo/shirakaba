<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\Mapping\EntityMap;
use Shirakaba\Orm\Mapping\Projection;
use Shirakaba\Orm\MappingError;
use Shirakaba\Orm\Tests\Fixtures\Author;
use Shirakaba\Orm\Tests\Fixtures\Comment;
use Shirakaba\Orm\Tests\Fixtures\Post;
use Shirakaba\Orm\Tests\Fixtures\Tag;

require_once __DIR__ . '/Fixtures/Invalid.php';

#[CoversClass(EntityMap::class)]
#[CoversClass(Projection::class)]
#[CoversClass(MappingError::class)]
final class MappingErrorsTest extends TestCase
{
    public function testValidEntitiesPassTheCheck(): void
    {
        $this->expectNotToPerformAssertions();
        Entities::check([Author::class, Post::class, Comment::class, Tag::class]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidEntities(): iterable
    {
        yield 'no table' => [Fixtures\NotAnEntity::class, 'add #[Table'];
        yield 'no id' => [Fixtures\WithoutId::class, 'at least one #[Id]'];
        yield 'mutable date' => [Fixtures\MutableDate::class, 'must be DateTimeImmutable'];
        yield 'generated not nullable' => [Fixtures\NonNullGenerated::class, 'nullable with the default null'];
        yield 'private field' => [Fixtures\PrivateField::class, 'promoted public property'];
    }

    #[DataProvider('invalidEntities')]
    public function testRejectsInvalidEntities(string $class, string $message): void
    {
        $this->expectException(MappingError::class);
        $this->expectExceptionMessage($message);
        /** @var class-string $class */
        EntityMap::of($class);
    }

    public function testProjectionsMustMatchTheRequestedRelations(): void
    {
        $map = EntityMap::of(Post::class);

        try {
            Projection::plan(Fixtures\ViewMissingRelation::class, $map, ['author' => []]);
            self::fail('A requested relation without a parameter was accepted.');
        } catch (MappingError $e) {
            self::assertStringContainsString('no parameter for the requested relation(s) author', $e->getMessage());
        }
        try {
            Projection::plan(Fixtures\ViewWithExtra::class, $map, ['author' => []]);
            self::fail('A parameter that is not a relation was accepted.');
        } catch (MappingError $e) {
            self::assertStringContainsString('$note is neither', $e->getMessage());
        }
        try {
            Projection::plan(Fixtures\ViewWithWrongType::class, $map, []);
            self::fail('A parameter of the wrong type was accepted.');
        } catch (MappingError $e) {
            self::assertStringContainsString('$id is string, but Shirakaba\\Orm\\Tests\\Fixtures\\Post::$id is int', $e->getMessage());
        }
        $this->expectException(MappingError::class);
        $this->expectExceptionMessage('has no relation "nope"');
        Entities::query(Post::class)
            ->with(self::unchecked('nope'))
            ->into(
                Fixtures\ViewMissingRelation::class,
                new \Shirakaba\Database\Database(static fn (): \PDO => new \PDO('sqlite::memory:'))->connect()->reader(),
            );
    }

    /**
     * Hides a deliberately wrong relation name from the shirakaba/phpstan ORM rule.
     */
    private static function unchecked(string $name): string
    {
        return $name;
    }
}
