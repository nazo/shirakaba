<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

use Shirakaba\Orm\Attribute\Items;

final readonly class NoteView
{
    /**
     * @param list<ReplyView> $replies
     */
    public function __construct(
        public Note $note,
        public Person $author,
        #[Items(ReplyView::class)] public array $replies,
    ) {}
}
