<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

final readonly class NotAnEntity
{
    public function __construct(public int $id) {}
}

#[Table('t')]
final readonly class WithoutId
{
    public function __construct(public int $id) {}
}

#[Table('t')]
final readonly class MutableDate
{
    public function __construct(#[Id] public int $id, public \DateTime $at) {}
}

#[Table('t')]
final readonly class NonNullGenerated
{
    public function __construct(#[Id, Generated] public int $id) {}
}

#[Table('t')]
final class PrivateField
{
    public function __construct(#[Id] public int $id, private string $secret = '')
    {
        unset($this->secret);
    }
}

final readonly class ViewMissingRelation
{
    public function __construct(public Post $post) {}
}

final readonly class ViewWithExtra
{
    public function __construct(public Post $post, public Author $author, public string $note) {}
}

final readonly class ViewWithWrongType
{
    public function __construct(public string $id, public string $title) {}
}
