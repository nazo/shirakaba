<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use InvalidArgumentException;
use Shirakaba\Json\ListOf;
use Shirakaba\Validation\Count;
use Shirakaba\Validation\Email;
use Shirakaba\Validation\Length;
use Shirakaba\Validation\Range;

final readonly class CreateUser
{
    /**
     * @param list<string> $tags
     * @param list<Address> $previous
     */
    public function __construct(
        #[Length(min: 1, max: 20)]
        public string $name,
        #[Email]
        public string $email,
        public Address $address,
        #[Range(min: 0, max: 150)]
        public ?int $age = null,
        public Role $role = Role::Member,
        #[ListOf('string')] #[Count(max: 3)]
        public array $tags = [],
        #[ListOf(Address::class)]
        public array $previous = [],
        public float $score = 0.0,
    ) {
        if ($role === Role::Admin && $age !== null && $age < 18) {
            throw new InvalidArgumentException('Admins must be adults: internal rule 42.');
        }
    }
}
