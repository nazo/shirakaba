<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

use Shirakaba\Json\ListOf;
use Shirakaba\Validation\Length;

final readonly class NewTask
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        #[Length(min: 1, max: 100)] public string $title,
        public ?Owner $owner = null,
        #[ListOf('string')] public array $labels = [],
        public Status $status = Status::Open,
    ) {}
}
