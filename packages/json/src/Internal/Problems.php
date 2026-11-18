<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Http\ProblemCode;

/**
 * Collects field errors while a document is mapped: type mismatches (400) separately from
 * constraint violations (422).
 *
 * @internal
 */
final class Problems
{
    /** Errors reported per kind; a body with thousands of bad fields gets a bounded answer. */
    public const int LIMIT = 100;

    /** @var list<array{pointer: string, detail: string, code: string}> */
    public array $mapping = [];

    /** @var list<array{pointer: string, detail: string, code: string}> */
    public array $constraints = [];

    private int $count = 0;

    /**
     * @param string $code a ProblemCode for the entry
     */
    public function mapping(string $pointer, string $detail, string $code = ProblemCode::INVALID_TYPE): void
    {
        $this->count++;
        if (count($this->mapping) < self::LIMIT) {
            $this->mapping[] = ['pointer' => $pointer === '' ? '/' : $pointer, 'detail' => $detail, 'code' => $code];
        }
    }

    public function constraint(string $pointer, string $detail): void
    {
        $this->count++;
        if (count($this->constraints) < self::LIMIT) {
            $this->constraints[] = ['pointer' => $pointer === '' ? '/' : $pointer, 'detail' => $detail, 'code' => ProblemCode::CONSTRAINT];
        }
    }

    /**
     * Every problem found, including those beyond the reported limit.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Appends a JSON Pointer segment (RFC 6901).
     */
    public static function pointer(string $base, string|int $segment): string
    {
        return $base . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
    }
}
