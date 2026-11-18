<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

use Shirakaba\Database\Reader;
use Shirakaba\Orm\Entities;

function loops(Reader $db): void
{
    $notes = Entities::query(Note::class)->fetch($db);
    foreach ($notes as $note) {
        Entities::get(Person::class, $note->authorId, $db);
        $db->fetchValue('SELECT COUNT(*) FROM replies WHERE note_id = ?', [$note->id]);
        echo $note->body;
    }
    $ids = array_map(static fn (Note $note): int => $note->authorId, $notes);
    Entities::query(Person::class)->whereIn('id', $ids)->fetch($db);
}
