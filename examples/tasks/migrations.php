<?php

declare(strict_types=1);

// For the CLI: vendor/bin/shirakaba-migrate status --config=examples/tasks/migrations.php

use Shirakaba\Database\Database;
use Shirakaba\Examples\Tasks\TaskSchema;
use Shirakaba\Migrations\Migrations;

$file = getenv('TASKS_DATABASE');

return new Migrations(
    new Database(static fn (): PDO => new PDO('sqlite:' . (is_string($file) && $file !== '' ? $file : sys_get_temp_dir() . '/shirakaba-example-tasks.sqlite'))),
    TaskSchema::migrations(),
);
