<?php

declare(strict_types=1);

use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\Info;
use Shirakaba\OpenApi\Operation;

return new Generator(
    new Info('Tasks', '1.0.0', 'A small task API.'),
    [
        'tasks.list' => new Operation(summary: 'List tasks', tags: ['tasks']),
        'tasks.delete' => new Operation(summary: 'Delete a task', tags: ['tasks'], deprecated: true),
    ],
    ['https://api.example.com'],
);
