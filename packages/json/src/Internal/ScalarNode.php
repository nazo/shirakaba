<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\Type;

/**
 * @internal
 */
final readonly class ScalarNode implements Node
{
    /**
     * @param 'string'|'int'|'float'|'bool' $kind
     */
    public function __construct(
        private string $kind,
        private bool $nullable,
    ) {}

    public function map(mixed $value, string $pointer, Problems $problems): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }

        $mapped = match ($this->kind) {
            'string' => is_string($value) ? $value : null,
            'int' => is_int($value) ? $value : null,
            // JSON has one number type; integers are valid floats.
            'float' => is_int($value) || is_float($value) ? (float) $value : null,
            'bool' => is_bool($value) ? $value : null,
        };
        if ($mapped === null) {
            $problems->mapping($pointer, sprintf('must be %s', match ($this->kind) {
                'string' => 'a string',
                'int' => 'an integer',
                'float' => 'a number',
                'bool' => 'a boolean',
            } . ($this->nullable ? ' or null' : '')));
        }

        return $mapped;
    }

    public function type(): Type
    {
        return new ScalarType($this->kind, $this->nullable);
    }
}
