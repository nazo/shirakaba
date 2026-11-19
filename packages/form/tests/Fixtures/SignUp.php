<?php

declare(strict_types=1);

namespace Shirakaba\Form\Tests\Fixtures;

use Shirakaba\Form\FormList;
use Shirakaba\Validation\Email;
use Shirakaba\Validation\Range;

final readonly class SignUp
{
    /**
     * @param list<string> $interests
     */
    public function __construct(
        #[Email] public string $email,
        #[Range(min: 13)] public ?int $age = null,
        public Plan $plan = Plan::Free,
        public bool $newsletter = false,
        #[FormList('string')] public array $interests = [],
    ) {}
}
