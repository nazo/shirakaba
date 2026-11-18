<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use DateTimeImmutable;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\RequiredScope;
use Shirakaba\Orm\Attribute\SoftDelete;
use Shirakaba\Orm\Attribute\Table;

#[Table('orm_issues')]
#[RequiredScope(InTeam::class)]
#[SoftDelete]
final readonly class Issue
{
    public function __construct(
        public int $team,
        public int $projectId,
        public string $title,
        public ?DateTimeImmutable $deletedAt = null,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
