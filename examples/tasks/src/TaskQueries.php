<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Database\Reader;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\EntityNotFound;
use Shirakaba\Orm\EntityQuery;

/**
 * Read-only access to tasks, built per request from a Reader (design/08-database.md).
 */
final readonly class TaskQueries
{
    public function __construct(
        private Reader $db,
    ) {}

    /**
     * @throws EntityNotFound as a 404
     */
    public function get(int $id): TaskView
    {
        return Entities::into(Entities::get(Task::class, $id, $this->db), TaskView::class);
    }

    /**
     * @param int<0, max> $offset
     * @param positive-int $limit
     * @return list<TaskView>
     */
    public function list(int $offset, int $limit, ?TaskStatus $status): array
    {
        return self::withStatus($status)->orderBy('id')->limit($limit)->offset($offset)->into(TaskView::class, $this->db);
    }

    public function count(?TaskStatus $status): int
    {
        return self::withStatus($status)->count($this->db);
    }

    /**
     * @return EntityQuery<Task>
     */
    private static function withStatus(?TaskStatus $status): EntityQuery
    {
        $query = Entities::query(Task::class);

        return $status === null ? $query : $query->where('status', '=', $status);
    }
}
