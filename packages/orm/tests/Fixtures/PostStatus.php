<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
