<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

use Shirakaba\Json\ListOf;

final readonly class Described
{
    /**
     * Shown as is.
     *
     * @var non-empty-string
     */
    public string $title;

    public function __construct(
        string $title,
        /**
         * Null when the owner was deleted,
         * or never set.
         *
         * Owners are people.
         */
        public ?Owner $owner,
        /** @var list<string> */
        #[ListOf('string')]
        public array $tags = [],
        public int $count = 0,
    ) {
        $this->title = $title === '' ? 'untitled' : $title;
    }
}
