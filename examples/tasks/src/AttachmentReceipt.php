<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class AttachmentReceipt
{
    public function __construct(
        public int $task,
        public string $name,
        public int $size,
        public string $note,
    ) {}
}
