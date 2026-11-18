<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Scopes;

use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\RequiredScope;
use Shirakaba\Orm\Attribute\Table;

#[Table('cards')]
#[RequiredScope(InOrganization::class)]
final readonly class Card
{
    public function __construct(
        public int $organizationId,
        public int $boardId,
        public string $body,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
