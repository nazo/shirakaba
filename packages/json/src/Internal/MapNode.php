<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Description\MapType;
use Shirakaba\Core\Description\Type;
use stdClass;

/**
 * Maps a JSON object with arbitrary keys into an array.
 *
 * @internal
 */
final readonly class MapNode implements Node
{
    public function __construct(
        private Node $values,
        private bool $nullable,
    ) {}

    public function map(mixed $value, string $pointer, Problems $problems): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }
        if (!$value instanceof stdClass) {
            $problems->mapping($pointer, 'must be an object' . ($this->nullable ? ' or null' : ''));

            return null;
        }

        $before = $problems->count();
        $items = [];
        foreach (get_object_vars($value) as $key => $item) {
            $items[$key] = $this->values->map($item, Problems::pointer($pointer, (string) $key), $problems);
        }

        return $problems->count() === $before ? $items : null;
    }

    public function type(): Type
    {
        return new MapType($this->values->type(), $this->nullable);
    }
}
