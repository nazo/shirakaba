<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Fixtures;

use Shirakaba\Core\Http\Text;
use Shirakaba\DatabaseBridge\Db;
use Shirakaba\Database\Writer;

final readonly class Invokable
{
    public function __invoke(#[Db] Writer $db): Text
    {
        return new Text('invoke');
    }
}
