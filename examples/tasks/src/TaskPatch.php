<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Json\Optional;
use Shirakaba\Json\OptionalOf;
use Shirakaba\Validation\Length;
use Shirakaba\Validation\NotBlank;

/**
 * A partial update (PATCH): only the fields that were sent change. With `version`, the
 * update fails with 409 when the task changed since the client read it.
 */
final readonly class TaskPatch
{
    /**
     * @param Optional<string> $title
     * @param Optional<string> $description
     * @param Optional<TaskStatus> $status
     * @param Optional<int> $version
     */
    public function __construct(
        #[OptionalOf('string')]
        #[NotBlank]
        #[Length(max: 200)] public Optional $title = new Optional(),
        #[OptionalOf('string')]
        #[Length(max: 2000)] public Optional $description = new Optional(),
        #[OptionalOf(TaskStatus::class)] public Optional $status = new Optional(),
        #[OptionalOf('int')] public Optional $version = new Optional(),
    ) {}
}
