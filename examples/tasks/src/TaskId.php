<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\Type;
use Shirakaba\Core\Extraction\RequestValue;
use Shirakaba\Core\Extraction\Scalar;
use Shirakaba\Validation\Range;

/**
 * A task ID: a positive integer. The rule lives here instead of on every handler parameter.
 */
final readonly class TaskId implements RequestValue
{
    private function __construct(
        public int $value,
    ) {}

    public static function fromRequestValue(string $value): ?static
    {
        $id = Scalar::int($value);

        return $id !== null && $id >= 1 ? new static($id) : null;
    }

    public static function requestValueType(): Type
    {
        return new ScalarType('int');
    }

    public static function requestValueConstraints(): array
    {
        return [new Range(min: 1)];
    }
}
