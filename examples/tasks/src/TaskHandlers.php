<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\NoContent;
use Shirakaba\Core\Http\Responds;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\Form\FormBody;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\JsonResponse;
use Shirakaba\Validation\Range;

/**
 * The task API's handlers, registered with first-class callables. Reads take TaskQueries
 * and writes take TaskCommands, both built per request by #[Db] (design/08-database.md). A missing task
 * is a 404 and a stale version a 409, both thrown by shirakaba/orm.
 */
final readonly class TaskHandlers
{
    /**
     * @param positive-int $page checked by #[Range] before the handler runs
     * @param int<1, 100> $perPage
     */
    #[JsonResponse(200, TaskPage::class)]
    public function list(
        #[Db] TaskQueries $tasks,
        #[Query]
        #[Range(min: 1)] int $page = 1,
        #[Query('per_page')]
        #[Range(min: 1, max: 100)] int $perPage = 20,
        #[Query] ?TaskStatus $status = null,
    ): Json {
        return new Json(new TaskPage($tasks->list(($page - 1) * $perPage, $perPage, $status), $page, $perPage, $tasks->count($status)));
    }

    #[JsonResponse(200, TaskView::class)]
    #[Responds(404, 'The task does not exist.')]
    public function show(#[Path] TaskId $id, #[Db] TaskQueries $tasks): Json
    {
        return new Json($tasks->get($id->value));
    }

    #[JsonResponse(201, TaskView::class, description: 'The created task; its URL is in Location.')]
    public function create(#[Db] TaskCommands $tasks, #[JsonBody] NewTask $input): Json
    {
        $task = $tasks->add($input->title, $input->description);

        return new Json($task, headers: ['Location' => '/tasks/' . $task->id]);
    }

    #[JsonResponse(200, TaskView::class)]
    #[Responds(404, 'The task does not exist.')]
    #[Responds(409, 'The task changed since the version that was sent.')]
    public function replace(#[Path] TaskId $id, #[Db] TaskCommands $tasks, #[JsonBody] TaskUpdate $input): Json
    {
        return new Json($tasks->change($id->value, [
            'title' => $input->title,
            'description' => $input->description,
            'status' => $input->status,
            'version' => $input->version,
        ]));
    }

    #[JsonResponse(200, TaskView::class)]
    #[Responds(404, 'The task does not exist.')]
    #[Responds(409, 'The task changed since the version that was sent.')]
    public function update(#[Path] TaskId $id, #[Db] TaskCommands $tasks, #[JsonBody] TaskPatch $input): Json
    {
        $changes = [];
        if ($input->title->isPresent()) {
            $changes['title'] = $input->title->get();
        }
        if ($input->description->isPresent()) {
            $changes['description'] = $input->description->get();
        }
        if ($input->status->isPresent()) {
            $changes['status'] = $input->status->get();
        }
        if ($input->version->isPresent()) {
            $changes['version'] = $input->version->get();
        }

        return new Json($tasks->change($id->value, $changes));
    }

    #[JsonResponse(200, TaskView::class)]
    #[Responds(404, 'The task does not exist.')]
    public function complete(#[Path] TaskId $id, #[Db] TaskCommands $tasks): Json
    {
        return new Json($tasks->change($id->value, ['status' => TaskStatus::Done]));
    }

    #[JsonResponse(200, CompletedTasks::class, description: 'How many open tasks were completed.')]
    public function completeAll(#[Db] TaskCommands $tasks): Json
    {
        return new Json(new CompletedTasks($tasks->completeAll()));
    }

    /**
     * Accepts an attachment. The example only reports what it received; a real application
     * would move the file to storage with $input->file->moveTo().
     */
    #[JsonResponse(201, AttachmentReceipt::class)]
    #[Responds(404, 'The task does not exist.')]
    public function attach(#[Path] TaskId $id, #[Db] TaskQueries $tasks, #[FormBody] NewAttachment $input): Json
    {
        $task = $tasks->get($id->value);

        return new Json(new AttachmentReceipt($task->id, $input->file->getClientFilename() ?? '', $input->file->getSize() ?? 0, $input->note));
    }

    #[Responds(204, 'The task was deleted.')]
    #[Responds(404, 'The task does not exist.')]
    public function delete(#[Path] TaskId $id, #[Db] TaskCommands $tasks): NoContent
    {
        return $tasks->delete($id->value) ? new NoContent() : throw HttpFailure::notFound(sprintf('Task %d does not exist.', $id->value));
    }
}
