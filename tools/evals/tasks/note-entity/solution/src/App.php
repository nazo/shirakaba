<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Database\Database;
use Shirakaba\DatabaseBridge\DatabaseMiddleware;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\Database\Reader;
use Shirakaba\Json\Json;
use Shirakaba\Orm\Entities;
use Shirakaba\Queue\Jobs;

final readonly class App
{
    public static function builder(Database $database, Jobs $jobs): AppBuilder
    {
        $app = new AppBuilder();
        $app->middleware(new DatabaseMiddleware($database));
        $app->get('/health', static fn (): Text => new Text('ok'))->name('health');
        $app->get('/notes', static function (#[Db] Reader $db): Json {
            $rows = Entities::query(Note::class)->with('author')->orderBy('createdAt', 'desc')->into(NoteRow::class, $db);

            return new Json(array_map(static fn (NoteRow $row): array => [
                'id' => $row->note->id,
                'title' => $row->note->title,
                'author' => $row->author->name,
            ], $rows));
        })->name('notes.list');

        return $app;
    }
}
