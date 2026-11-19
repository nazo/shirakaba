<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Shirakaba\PHPStan\EntityQueryRule;

/**
 * @extends RuleTestCase<EntityQueryRule>
 */
final class EntityQueryRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new EntityQueryRule(self::createReflectionProvider());
    }

    public function testChecksNamesTypesAndProjections(): void
    {
        $note = 'Shirakaba\PHPStan\Tests\Orm\Note';
        $this->analyse([__DIR__ . '/data/orm/queries.php'], [
            [$note . ' has no stored property "title".', 14],
            [$note . '::$pinned is bool, but the value is string.', 15],
            [$note . ' has no stored property "created".', 16],
            [
                $note
                    . ' has no relation "autor"; relations are declared on the class with #[BelongsTo], #[HasOne], #[HasMany] or #[ManyToMany]. See vendor/shirakaba/docs/messages/unknown-relation.md.',
                18,
            ],
            [
                'Projection Shirakaba\PHPStan\Tests\Orm\NoteView::__construct() parameter $replies is neither the '
                    . $note
                    . ', one of its properties, nor a relation requested with with(); mark values computed elsewhere #[Computed]. See vendor/shirakaba/docs/messages/projection-mismatch.md.',
                19,
            ],
            [
                $note
                    . ' has no relation "repl"; relations are declared on the class with #[BelongsTo], #[HasOne], #[HasMany] or #[ManyToMany]. See vendor/shirakaba/docs/messages/unknown-relation.md.',
                20,
            ],
            [$note . ' has no stored property "title".', 21],
            [$note . '::$body is string, but the value is int.', 22],
            [
                'Projection Shirakaba\PHPStan\Tests\Orm\ReplyView::__construct() parameter $reply is neither the '
                    . $note
                    . ', one of its properties, nor a relation requested with with(); mark values computed elsewhere #[Computed]. See vendor/shirakaba/docs/messages/projection-mismatch.md.',
                25,
            ],
        ]);
    }

    public function testReadsRelationsSpreadFromConstants(): void
    {
        $note = 'Shirakaba\PHPStan\Tests\Orm\Note';
        $this->analyse([__DIR__ . '/data/orm/SpreadQueries.php'], [
            [
                $note
                    . ' has no relation "autor"; relations are declared on the class with #[BelongsTo], #[HasOne], #[HasMany] or #[ManyToMany]. See vendor/shirakaba/docs/messages/unknown-relation.md.',
                21,
            ],
            [
                'Projection Shirakaba\PHPStan\Tests\Orm\ReplyView::__construct() parameter $author is neither the Shirakaba\PHPStan\Tests\Orm\Reply, one of its properties, nor a relation requested with with(); mark values computed elsewhere #[Computed]. See vendor/shirakaba/docs/messages/projection-mismatch.md.',
                22,
            ],
            [
                'Projection Shirakaba\PHPStan\Tests\Orm\NoteView::__construct() parameter $replies is neither the '
                    . $note
                    . ', one of its properties, nor a relation requested with with(); mark values computed elsewhere #[Computed]. See vendor/shirakaba/docs/messages/projection-mismatch.md.',
                23,
            ],
        ]);
    }

    public function testChecksPropertiesInsideGroups(): void
    {
        $note = 'Shirakaba\PHPStan\Tests\Orm\Note';
        $this->analyse([__DIR__ . '/data/orm/GroupQueries.php'], [
            [$note . ' has no stored property "title".', 14],
            [$note . '::$pinned is bool, but the value is string.', 15],
            [$note . ' has no stored property "created".', 16],
        ]);
    }
}
