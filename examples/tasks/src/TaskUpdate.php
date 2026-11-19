<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Validation\Length;
use Shirakaba\Validation\NotBlank;

/**
 * A full replacement (PUT); see TaskPatch for partial updates. `version` is the one the
 * client read, so a replacement based on an outdated copy fails with 409.
 */
final readonly class TaskUpdate
{
    public function __construct(
        #[NotBlank]
        #[Length(max: 200)] public string $title,
        #[Length(max: 2000)] public string $description,
        public TaskStatus $status,
        public int $version,
    ) {}
}
