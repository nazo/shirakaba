<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests\Fixtures;

use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\Type;
use Shirakaba\Core\Extraction\RequestValue;

final readonly class Slug implements RequestValue
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromRequestValue(string $value): ?static
    {
        return preg_match('/^[a-z0-9-]+$/D', $value) === 1 ? new static($value) : null;
    }

    public static function requestValueType(): Type
    {
        return new ScalarType('string');
    }

    public static function requestValueConstraints(): array
    {
        return [new AtLeast(0)];
    }
}
