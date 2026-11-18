<?php

declare(strict_types=1);

// Returns the application's builder; used by public/index.php and by shirakaba-openapi.

use Shirakaba\Examples\Tasks\TaskApi;
use Shirakaba\Examples\Tasks\TaskSchema;

return TaskApi::builder(TaskSchema::database(sys_get_temp_dir() . '/shirakaba-example-tasks.sqlite'));
