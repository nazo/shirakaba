<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonUnion;

/** A member declares the discriminator itself, which the union writes. */
#[JsonUnion('type', ['tagged' => TaggedMember::class])]
interface BadUnion {}
