<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\Orm\Entities;

function queries(Reader $db, Writer $writer): void
{
    Entities::query(Note::class)->where('body', '=', 'x')->orderBy('id')->fetch($db);
    Entities::query(Note::class)->where('title', '=', 'x')->fetch($db);
    Entities::query(Note::class)->where('pinned', '=', 'yes')->fetch($db);
    Entities::query(Note::class)->whereIn('authorId', [1, 2])->orderBy('created')->fetch($db);
    Entities::query(Note::class)->with('author', 'replies.author')->into(NoteView::class, $db);
    Entities::query(Note::class)->with('autor')->into(NoteView::class, $db);
    Entities::query(Note::class)->with('author')->into(NoteView::class, $db);
    Entities::query(Note::class)->whereHas('repl')->fetch($db);
    Entities::patch(Note::class, 1, ['pinned' => true, 'title' => 'x'], $writer);
    Entities::patch(Note::class, 1, ['body' => 5], $writer);
    Entities::query(Note::class)->into(NoteCard::class, $db);
    Entities::into(Entities::get(Note::class, 1, $db), NoteCard::class);
    Entities::into(Entities::get(Note::class, 1, $db), ReplyView::class);
}
