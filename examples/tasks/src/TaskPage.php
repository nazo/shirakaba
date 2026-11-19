<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Json\JsonDto;
use Shirakaba\Json\JsonName;
use Shirakaba\Json\ListOf;

/**
 * One page of tasks, encoded from its constructor fields (#[JsonDto]).
 */
#[JsonDto]
final readonly class TaskPage
{
    /**
     * @param list<TaskView> $items
     */
    public function __construct(
        #[ListOf(TaskView::class)] public array $items,
        public int $page,
        #[JsonName('per_page')] public int $perPage,
        public int $total,
    ) {}
}
