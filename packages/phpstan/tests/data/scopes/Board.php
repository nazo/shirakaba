<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Scopes;

use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\HasMany;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\RequiredScope;
use Shirakaba\Orm\Attribute\Table;

#[Table('boards')]
#[RequiredScope(InOrganization::class)]
#[HasMany('cards', Card::class, 'boardId')]
final readonly class Board
{
    public function __construct(
        public int $organizationId,
        public string $title,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
