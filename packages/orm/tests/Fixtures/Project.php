<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use DateTimeImmutable;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\HasMany;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\RequiredScope;
use Shirakaba\Orm\Attribute\SoftDelete;
use Shirakaba\Orm\Attribute\Table;

#[Table('orm_projects')]
#[RequiredScope(InTeam::class)]
#[SoftDelete]
#[HasMany('issues', Issue::class, 'projectId', orderBy: 'id')]
final readonly class Project
{
    public function __construct(
        public int $team,
        public string $name,
        public ?DateTimeImmutable $deletedAt = null,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
