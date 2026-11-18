<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Json\ListOf;
use Shirakaba\Time\LocalDate;

#[JsonDto]
final readonly class AuthorSettings
{
    /**
     * @param list<string> $languages
     */
    public function __construct(
        public bool $newsletter,
        #[ListOf('string')] public array $languages = [],
        public ?LocalDate $vacationUntil = null,
    ) {}
}
