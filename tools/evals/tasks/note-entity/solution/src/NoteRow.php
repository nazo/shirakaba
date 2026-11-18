<?php

declare(strict_types=1);

namespace App;

/**
 * A note with its author, read with with('author').
 */
final readonly class NoteRow
{
    public function __construct(
        public Note $note,
        public User $author,
    ) {}
}
