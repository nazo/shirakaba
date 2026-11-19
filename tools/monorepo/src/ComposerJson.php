<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Monorepo;

use JsonException;
use stdClass;

/**
 * Strict, typed read-only view over a decoded composer.json document.
 *
 * Malformed values are reported instead of being skipped, so a typo in a manifest
 * cannot silently disable a check.
 */
final readonly class ComposerJson
{
    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(
        public string $path,
        private array $data,
    ) {}

    /**
     * @throws ValidationFailure when the file is missing or is not a JSON object
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new ValidationFailure(sprintf('%s does not exist.', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ValidationFailure(sprintf('%s cannot be read.', $path));
        }

        try {
            // Decoding to objects first tells "{}" apart from "[]".
            if (!json_decode($contents, false, 512, JSON_THROW_ON_ERROR) instanceof stdClass) {
                throw new ValidationFailure(sprintf('%s must contain a JSON object.', $path));
            }
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ValidationFailure(sprintf('%s is not valid JSON: %s', $path, $e->getMessage()), previous: $e);
        }

        return new self($path, is_array($data) ? $data : []);
    }

    /**
     * @throws ValidationFailure when the value exists but is not a string
     */
    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new ValidationFailure(sprintf('%s: "%s" must be a string.', $this->path, $key));
        }

        return $value;
    }

    /**
     * Returns the object at $path as a map of strings; a missing object yields [].
     *
     * @return array<string, string>
     * @throws ValidationFailure when the object or one of its values has the wrong type
     */
    public function stringMap(string ...$path): array
    {
        $node = $this->data;
        foreach ($path as $segment) {
            $next = $node[$segment] ?? null;
            if ($next === null) {
                return [];
            }
            if (!is_array($next) || $next !== [] && array_is_list($next)) {
                throw new ValidationFailure(sprintf('%s: "%s" must be a JSON object.', $this->path, implode('.', $path)));
            }
            $node = $next;
        }

        $result = [];
        foreach ($node as $key => $value) {
            if (!is_string($value)) {
                throw new ValidationFailure(sprintf('%s: "%s.%s" must be a string.', $this->path, implode('.', $path), $key));
            }
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
