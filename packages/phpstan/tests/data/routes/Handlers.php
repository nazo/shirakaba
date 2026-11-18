<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Fixtures;

use Shirakaba\Core\Http\Text;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;

final readonly class Handlers
{
    public function show(#[Db] Reader $db): Text
    {
        return new Text('show');
    }

    public function touch(#[Db] Writer $db): Text
    {
        return new Text('touch');
    }
}
