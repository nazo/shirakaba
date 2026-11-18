<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use JsonException;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Json\Internal\Planner;
use Shirakaba\Json\Internal\Problems;
use UnexpectedValueException;

/**
 * Maps JSON text into a DTO with the same rules as #[JsonBody] (design/06-json-and-openapi.md), outside
 * requests: stored JSON columns, messages, files.
 *
 *     $settings = JsonMapper::decode($json, Settings::class);
 *     $json = Json::encode($settings);
 */
final readonly class JsonMapper
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param positive-int $maxDepth
     * @return T
     * @throws InvalidApplication when $class cannot be mapped
     * @throws UnexpectedValueException when the JSON is malformed or does not match $class
     */
    public static function decode(string $json, string $class, bool $allowUnknownFields = false, int $maxDepth = 64): object
    {
        $node = new Planner($allowUnknownFields, $class)->root($class);
        try {
            $document = json_decode($json, false, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException(sprintf('Invalid JSON for %s: %s', $class, $e->getMessage()), 0, $e);
        }
        $problems = new Problems();
        $value = $node->map($document, '', $problems);
        $errors = [...$problems->mapping, ...$problems->constraints];
        if ($errors !== [] || !$value instanceof $class) {
            throw new UnexpectedValueException(sprintf(
                'JSON does not match %s: %s',
                $class,
                implode('; ', array_map(static fn (array $error): string => $error['pointer'] . ' ' . $error['detail'], $errors)),
            ));
        }

        return $value;
    }
}
