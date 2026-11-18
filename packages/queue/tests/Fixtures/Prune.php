<?php

declare(strict_types=1);

namespace Shirakaba\Queue\Tests\Fixtures;

use Shirakaba\Json\JsonDto;

/** A job that needs no data. */
#[JsonDto]
final readonly class Prune {}
