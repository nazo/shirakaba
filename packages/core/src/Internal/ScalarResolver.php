<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Core\Extraction\ExtractionFailure;

/**
 * Resolves a path, query, header or cookie parameter. A plain serialisable object, so
 * build plans can be cached (design/04-routing-and-handlers.md).
 *
 * @internal
 */
final readonly class ScalarResolver implements ArgumentResolver
{
    /**
     * @param 'path'|'query'|'header'|'cookie' $source
     */
    public function __construct(
        private string $source,
        private string $name,
        private ScalarConverter $converter,
    ) {}

    public function resolve(Exchange $exchange): mixed
    {
        $label = match ($this->source) {
            'path' => 'path parameter',
            'query' => 'query parameter',
            'header' => 'header',
            'cookie' => 'cookie',
        };

        $values = match ($this->source) {
            'path' => isset($exchange->routeParameters[$this->name]) ? [$exchange->routeParameters[$this->name]] : [],
            'query' => self::query($exchange->request->getUri()->getQuery(), $this->name),
            'header' => array_values($exchange->request->getHeader($this->name)),
            'cookie' => self::cookie($exchange, $this->name),
        };
        if (count($values) > 1) {
            throw ExtractionFailure::repeated($label, $this->name);
        }
        $value = $values[0] ?? null;

        return $value === null
            ? $this->converter->missing($label, $this->name)
            : $this->converter->extract($value, $label, $this->name);
    }

    /**
     * Parses the raw query string: PHP's parse_str() renames keys and keeps only the last
     * of repeated keys.
     *
     * @return list<string>
     */
    private static function query(string $query, string $name): array
    {
        $values = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $separator = strpos($pair, '=');
            $key = $separator === false ? $pair : substr($pair, 0, $separator);
            if (urldecode($key) === $name) {
                $values[] = $separator === false ? '' : urldecode(substr($pair, $separator + 1));
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function cookie(Exchange $exchange, string $name): array
    {
        $value = $exchange->request->getCookieParams()[$name] ?? null;
        if (is_string($value)) {
            return [$value];
        }
        foreach (explode(';', $exchange->request->getHeaderLine('Cookie')) as $pair) {
            $separator = strpos($pair, '=');
            if ($separator !== false && trim(substr($pair, 0, $separator)) === $name) {
                return [urldecode(trim(substr($pair, $separator + 1)))];
            }
        }

        return [];
    }
}
