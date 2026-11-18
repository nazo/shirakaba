<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

use Shirakaba\Database\Reader;
use Shirakaba\Orm\Entities;

final class SpreadQueries
{
    private const array RELATIONS = ['author', 'replies.author'];
    private const array MISSPELT = ['autor'];

    /**
     * @param list<string> $names
     */
    public static function queries(Reader $db, string $dynamic, array $names): void
    {
        Entities::query(Note::class)->with(...self::RELATIONS)->into(NoteView::class, $db);
        Entities::query(Note::class)->with(...self::MISSPELT)->fetch($db);
        Entities::query(Note::class)->with('author', ...['replies'])->into(NoteView::class, $db);
        Entities::query(Note::class)->with(...['author'])->into(NoteView::class, $db);
        Entities::query(Note::class)->with('author', ...$names)->into(NoteView::class, $db);
        Entities::query(Note::class)->with($dynamic)->into(NoteView::class, $db);
    }
}
