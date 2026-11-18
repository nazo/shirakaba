<?php

declare(strict_types=1);

use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\Info;
use Shirakaba\OpenApi\Operation;

return new Generator(
    new Info('Tasks', '1.0.0', 'Example application of the Shirakaba framework.'),
    [
        'tasks.list' => new Operation(summary: 'List tasks', tags: ['tasks']),
        'tasks.create' => new Operation(summary: 'Create a task', tags: ['tasks']),
        'tasks.show' => new Operation(summary: 'Show a task', tags: ['tasks']),
        'tasks.replace' => new Operation(summary: 'Replace a task', tags: ['tasks']),
        'tasks.update' => new Operation(summary: 'Update some fields of a task', tags: ['tasks']),
        'tasks.complete' => new Operation(summary: 'Mark a task as done', tags: ['tasks']),
        'tasks.delete' => new Operation(summary: 'Delete a task', tags: ['tasks']),
        'tasks.attach' => new Operation(summary: 'Attach a file to a task', tags: ['tasks']),
    ],
);
