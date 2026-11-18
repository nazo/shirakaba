<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

use ReflectionProperty;
use Shirakaba\Template\Html;
use Shirakaba\Template\TemplateError;
use Stringable;

/**
 * Checks one parsed template against its data class.
 *
 * @internal
 */
final readonly class CheckContext
{
    /**
     * @param class-string $class
     */
    public function __construct(
        private Parsed $template,
        private string $class,
    ) {}

    /**
     * Types are lists of atoms: ['class', name], ['scalar', name], ['list', item types] or
     * ['mixed', ''], where scalar names are string, int, bool, null, float, array and content.
     *
     * @param list<Node> $nodes
     * @param array<string, list<array{string, mixed}>> $scope
     * @return list<string>
     */
    public function nodes(array $nodes, array $scope): array
    {
        $problems = [];
        foreach ($nodes as $node) {
            if ($node instanceof PrintNode) {
                $type = $this->resolve($node->path, $scope, $problems);
                $problems = [...$problems, ...$this->printable($node->path, $type)];
            } elseif ($node instanceof IfNode) {
                foreach ($node->branches as [$condition, $body]) {
                    $type = $this->resolve($condition->path, $scope, $problems);
                    foreach ($type as $atom) {
                        $ok = match ($atom[0]) {
                            'scalar' => in_array($atom[1], ['bool', 'string', 'null'], true),
                            'list', 'mixed' => true,
                            default => false,
                        };
                        if (!$ok) {
                            $problems[] = $this->problem($condition->path, sprintf('%s may be %s; conditions test booleans, strings, lists and null.', $condition->path->toString(), self::describe($atom)));
                            break;
                        }
                    }
                    $problems = [...$problems, ...$this->nodes($body, $scope)];
                }
                $problems = [...$problems, ...$this->nodes($node->else, $scope)];
            } elseif ($node instanceof ForNode) {
                $type = $this->resolve($node->list, $scope, $problems);
                $items = [];
                foreach ($type as $atom) {
                    if ($atom[0] === 'list') {
                        /** @var list<array{string, mixed}> $itemTypes */
                        $itemTypes = $atom[1];
                        $items = [...$items, ...$itemTypes];
                    } elseif ($atom[0] === 'mixed' || ($atom[0] === 'scalar' && $atom[1] === 'array')) {
                        $items[] = ['mixed', ''];
                    } else {
                        $problems[] = $this->problem($node->list, sprintf('%s may be %s; {%% for %%} iterates over lists.', $node->list->toString(), self::describe($atom)));
                    }
                }
                $inner = [...$scope, $node->variable => $items === [] ? [['mixed', '']] : $items, 'loop' => [['class', Loop::class]]];
                $problems = [...$problems, ...$this->nodes($node->body, $inner), ...$this->nodes($node->else, $scope)];
            }
        }

        return $problems;
    }

    /**
     * @param array<string, list<array{string, mixed}>> $scope
     * @param list<string> $problems
     * @return list<array{string, mixed}>
     */
    private function resolve(Path $path, array $scope, array &$problems): array
    {
        [$first] = $path->segments;
        $type = $scope[$first] ?? $this->propertyType($this->class, $first, $path, $problems);
        $walked = $first;
        foreach (array_slice($path->segments, 1) as $segment) {
            $next = [];
            foreach ($type as $atom) {
                if ($atom[0] === 'mixed') {
                    $next[] = ['mixed', ''];
                } elseif ($atom[0] === 'class' && is_string($atom[1])) {
                    /** @var class-string $class */
                    $class = $atom[1];
                    $next = [...$next, ...$this->propertyType($class, $segment, $path, $problems, $walked)];
                } elseif ($atom !== ['scalar', 'null']) {
                    $problems[] = $this->problem($path, sprintf('%s may be %s, which has no property "%s".', $walked, self::describe($atom), $segment));

                    return [['mixed', '']];
                }
            }
            $type = $next === [] ? [['mixed', '']] : $next;
            $walked .= '.' . $segment;
        }

        return $type;
    }

    /**
     * @param class-string $class
     * @param list<string> $problems
     * @return list<array{string, mixed}>
     */
    private function propertyType(string $class, string $name, Path $path, array &$problems, ?string $owner = null): array
    {
        $label = $owner === null ? $name : $owner . '.' . $name;
        if (!property_exists($class, $name)) {
            $problems[] = $this->problem($path, sprintf('%s does not exist: %s has no property "%s".', $label, $class, $name));

            return [['mixed', '']];
        }
        $property = new ReflectionProperty($class, $name);
        if (!$property->isPublic() || $property->isStatic()) {
            $problems[] = $this->problem($path, sprintf('%s is not a public property of %s.', $label, $class));

            return [['mixed', '']];
        }

        return Types::of($property);
    }

    /**
     * @param list<array{string, mixed}> $type
     * @return list<string>
     */
    private function printable(Path $path, array $type): array
    {
        foreach ($type as $atom) {
            $ok = match ($atom[0]) {
                'mixed' => true,
                'scalar' => in_array($atom[1], ['string', 'int', 'content'], true),
                'class' => is_string($atom[1]) && (is_a($atom[1], Stringable::class, true) || ($atom[1] === Html::class && $this->template->html)),
                default => false,
            };
            if (!$ok) {
                return [$this->problem($path, sprintf('%s may be %s, which %s template cannot print; print string, int, Stringable%s.', $path->toString(), self::describe($atom), $this->template->html ? 'an HTML' : 'a text', $this->template->html ? ' or Html' : ''))];
            }
        }

        return [];
    }

    /**
     * @param array{string, mixed} $atom
     */
    private static function describe(array $atom): string
    {
        return match ($atom[0]) {
            'list' => 'a list',
            'class', 'scalar' => is_string($atom[1]) ? $atom[1] : 'unknown',
            default => 'anything',
        };
    }

    private function problem(Path $path, string $message): string
    {
        return new TemplateError($message, $this->template->name, $path->at->line, $path->at->column)->getMessage();
    }
}
