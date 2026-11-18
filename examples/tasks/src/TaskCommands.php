<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Database\Writer;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\EntityNotFound;
use Shirakaba\Orm\StaleEntity;

/**
 * Changes to tasks, built per request from a Writer (design/08-database.md). Handlers for GET routes
 * cannot take it: the PHPStan rule of design/13-static-analysis-and-agents.md reports them. Every write is one statement
 * the method asks for; there is no unit of work.
 */
final readonly class TaskCommands
{
    public function __construct(
        private Writer $db,
    ) {}

    public function add(string $title, string $description): TaskView
    {
        return Entities::into(Entities::insert(new Task($title, $description), $this->db), TaskView::class);
    }

    /**
     * Changes the named properties of a task. A `version` among the changes is the one the
     * client read: the write fails when the stored task has moved on (optimistic locking).
     *
     * @param array{title?: string, description?: string, status?: TaskStatus, version?: int} $changes
     * @throws EntityNotFound as a 404
     * @throws StaleEntity as a 409
     */
    public function change(int $id, array $changes): TaskView
    {
        $task = Entities::get(Task::class, $id, $this->db);

        return Entities::into(Entities::update(Entities::change($task, $changes), $this->db), TaskView::class);
    }

    /**
     * Marks every open task as done with one UPDATE; the version of each row increases, so
     * clients holding an older copy get a 409 on their next write.
     *
     * @return int the number of tasks completed
     */
    public function completeAll(): int
    {
        return Entities::query(Task::class)->where('status', '=', TaskStatus::Open)->update(['status' => TaskStatus::Done], $this->db);
    }

    /**
     * @return bool whether the task existed
     */
    public function delete(int $id): bool
    {
        return Entities::query(Task::class)->where('id', '=', $id)->delete($this->db) > 0;
    }
}
