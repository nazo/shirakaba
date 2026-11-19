<?php

declare(strict_types=1);

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Header;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\NoContent;
use Shirakaba\Core\Http\Text;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\OpenApi\Tests\Fixtures\NewTask;
use Shirakaba\OpenApi\Tests\Fixtures\Opaque;
use Shirakaba\OpenApi\Tests\Fixtures\Status;
use Shirakaba\Validation\Range;

$app = new AppBuilder();
$app->get(
    '/tasks',
    static fn (
        #[Query]
        #[Range(min: 1)] int $page = 1,
        #[Query] ?Status $status = null,
    ): Json => new Json([]),
)->name('tasks.list');
$app->post('/tasks', static fn (#[JsonBody] NewTask $task): Json => new Json(['title' => $task->title], 201))->name('tasks.create');
$app->delete('/tasks/{id}', static fn (#[Path] int $id, #[Header('If-Match')] string $etag): NoContent => new NoContent())->name('tasks.delete');
$app->get('/files/{path...}', static fn (): Text => new Text('file'));
$app->get('/opaque', static fn (#[Opaque] string $value): Text => new Text($value));
$app->route(['PURGE'], '/cache', static fn (): NoContent => new NoContent());

return $app;
