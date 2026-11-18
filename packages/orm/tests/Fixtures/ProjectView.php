<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

final readonly class ProjectView
{
    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        public Project $project,
        public array $issues,
    ) {}
}
