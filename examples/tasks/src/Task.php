<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use DateTimeImmutable;
use Shirakaba\Orm\Attribute\CreatedAt;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;
use Shirakaba\Orm\Attribute\Version;

/**
 * A task as stored (shirakaba/orm). The key, the creation time and the version are filled by
 * Entities::insert(), so a new task leaves them out; parameters with defaults must come
 * last in PHP, which is why the key is at the end. Responses use TaskView, whose fields
 * are not nullable.
 */
#[Table('tasks', label: 'task')]
final readonly class Task
{
    public function __construct(
        public string $title,
        public string $description = '',
        public TaskStatus $status = TaskStatus::Open,
        #[CreatedAt] public ?DateTimeImmutable $createdAt = null,
        #[Version] public int $version = 0,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
