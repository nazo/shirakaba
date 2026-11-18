<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Description\ListType;
use Shirakaba\Core\Description\Type;

/**
 * @internal
 */
final readonly class ListNode implements Node
{
    public function __construct(
        private Node $items,
        private bool $nullable,
    ) {}

    public function map(mixed $value, string $pointer, Problems $problems): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }
        // Decoded with objects as stdClass, so an array here is always a JSON array.
        if (!is_array($value)) {
            $problems->mapping($pointer, 'must be an array' . ($this->nullable ? ' or null' : ''));

            return null;
        }

        $before = $problems->count();
        $items = [];
        foreach (array_values($value) as $index => $item) {
            $items[] = $this->items->map($item, Problems::pointer($pointer, $index), $problems);
        }

        return $problems->count() === $before ? $items : null;
    }

    public function type(): Type
    {
        return new ListType($this->items->type(), $this->nullable);
    }
}
