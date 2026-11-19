<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use DateTimeImmutable;
use Shirakaba\Json\JsonDto;
use Shirakaba\Json\JsonName;

/**
 * A task as the API returns it, published as the schema "Task". The ORM fills it by
 * property name (EntityQuery::into(), Entities::into()), so it needs no mapping code; a
 * task that was never stored has no id and fails to map instead of reaching the client.
 */
#[JsonDto(name: 'Task')]
final readonly class TaskView
{
    public function __construct(
        public int $id,
        public string $title,
        public string $description,
        public TaskStatus $status,
        #[JsonName('created_at')] public DateTimeImmutable $createdAt,
        public int $version,
    ) {}
}
