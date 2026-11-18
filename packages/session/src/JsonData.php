<?php

declare(strict_types=1);

namespace Shirakaba\Session;

use JsonException;

/**
 * Session data as JSON, the format every session store keeps, so a store never PHP-serialises
 * data (a stolen store cannot inject objects).
 */
final readonly class JsonData
{
    /**
     * @param array<string, mixed> $data
     * @throws JsonException when the data is not JSON-encodable
     */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $json): ?array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
