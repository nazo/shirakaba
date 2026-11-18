<?php

declare(strict_types=1);

namespace Shirakaba\Core\Routing;

use Shirakaba\Core\InvalidApplication;

/**
 * A parsed route path template such as "/users/{id}" or "/files/{path...}" (design/04-routing-and-handlers.md).
 *
 * @internal
 */
final readonly class PathTemplate
{
    /**
     * @param non-empty-list<Segment> $segments
     */
    private function __construct(
        public string $template,
        public array $segments,
    ) {}

    /**
     * @throws InvalidApplication when the template is malformed
     */
    public static function parse(string $template): self
    {
        if (!str_starts_with($template, '/')) {
            throw new InvalidApplication(sprintf('Route path "%s" must start with "/".', $template));
        }

        $parts = explode('/', substr($template, 1));
        $last = count($parts) - 1;
        $segments = [];
        $names = [];
        foreach ($parts as $index => $part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\.\.\.)?\}$/D', $part, $match) === 1) {
                $name = $match[1];
                // An unmatched trailing group is absent from $match.
                $catchAll = isset($match[2]);
                if ($catchAll && $index !== $last) {
                    throw new InvalidApplication(sprintf('Route path "%s": the catch-all "{%s...}" must be the last segment.', $template, $name));
                }
                if (isset($names[$name])) {
                    throw new InvalidApplication(sprintf('Route path "%s": parameter "%s" is used twice.', $template, $name));
                }
                $names[$name] = true;
                $segments[] = $catchAll ? Segment::catchAll($name) : Segment::parameter($name);
            } elseif (strpbrk($part, '{}') !== false) {
                throw new InvalidApplication(sprintf('Route path "%s": segment "%s" is not a valid parameter; parameters are "{name}" or "{name...}".', $template, $part));
            } else {
                $segments[] = Segment::static($part);
            }
        }

        return new self($template, $segments);
    }

    /**
     * Identifies templates that match exactly the same paths, whatever their parameter names.
     * @phpstan-pure
     */
    public function shape(): string
    {
        return implode('/', array_map(
            static fn (Segment $segment): string => match ($segment->kind) {
                SegmentKind::Static => 's' . rawurlencode($segment->value),
                SegmentKind::Parameter => '{}',
                SegmentKind::CatchAll => '{...}',
            },
            $this->segments,
        ));
    }

    /**
     * @return list<string> parameter names in order of appearance
     * @phpstan-pure
     */
    public function parameterNames(): array
    {
        $names = [];
        foreach ($this->segments as $segment) {
            if ($segment->kind !== SegmentKind::Static) {
                $names[] = $segment->value;
            }
        }

        return $names;
    }

    /**
     * A key whose byte order is the precedence order of design/04-routing-and-handlers.md: one digit per segment
     * (static 0, parameter 1, catch-all 2), so the first differing segment decides and a
     * shorter template sorts before a longer one sharing its prefix.
     * @phpstan-pure
     */
    public function precedenceKey(): string
    {
        $key = '';
        foreach ($this->segments as $segment) {
            $key .= $segment->kind->value;
        }

        return $key;
    }

    /**
     * Orders templates by precedence: the first segment whose kinds differ decides.
     *
     * @return int<-1, 1>
     */
    public static function compare(self $a, self $b): int
    {
        foreach ($a->segments as $index => $segment) {
            $other = $b->segments[$index] ?? null;
            if ($other === null) {
                return 1;
            }
            $order = $segment->kind->value <=> $other->kind->value;
            if ($order !== 0) {
                return $order;
            }
        }

        return count($a->segments) < count($b->segments) ? -1 : 0;
    }

    /**
     * Matches decoded request path segments.
     *
     * @param non-empty-list<string> $path
     * @return list<string>|null parameter values in order of appearance, or null when the path does not match
     * @phpstan-pure
     */
    public function match(array $path): ?array
    {
        $values = [];
        foreach ($this->segments as $index => $segment) {
            if ($segment->kind === SegmentKind::CatchAll) {
                $rest = array_slice($path, $index);
                if ($rest === []) {
                    return null;
                }
                foreach ($rest as $part) {
                    if (str_contains($part, '/')) {
                        return null;
                    }
                }
                $values[] = implode('/', $rest);

                return $values;
            }

            $part = $path[$index] ?? null;
            if ($part === null) {
                return null;
            }
            if ($segment->kind === SegmentKind::Static) {
                if ($part !== $segment->value) {
                    return null;
                }
                continue;
            }
            if ($part === '' || str_contains($part, '/')) {
                return null;
            }
            $values[] = $part;
        }

        return count($path) === count($this->segments) ? $values : null;
    }
}
