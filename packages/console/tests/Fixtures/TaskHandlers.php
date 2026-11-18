<?php

declare(strict_types=1);

namespace Shirakaba\Console\Tests\Fixtures;

use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\Responds;
use Shirakaba\Core\Http\Text;

final readonly class TaskHandlers
{
    #[Responds(200, 'The task.', contentType: 'text/plain')]
    #[Responds(404, 'No such task.')]
    public function show(#[Path] int $id, #[Query] bool $verbose = false): Text
    {
        return new Text((string) $id);
    }
}
