<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

final readonly class ReplyView
{
    public function __construct(
        public Reply $reply,
        public Person $author,
    ) {}
}
