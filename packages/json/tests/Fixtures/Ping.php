<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;

/** A DTO without fields, such as a job that needs no data. */
#[JsonDto]
final readonly class Ping {}
