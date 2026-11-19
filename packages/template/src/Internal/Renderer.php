<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

use ReflectionProperty;
use Shirakaba\Template\Html;
use Shirakaba\Template\TemplateError;
use Stringable;

/**
 * Renders a parsed template. Values come from the public properties of the data object and of
 * nested objects; printable values are string, int, Stringable and, in HTML, Html (design/12-mail-and-templates.md).
 * It keeps nothing between renders.
 *
 * @internal
 */
final readonly class Renderer
{
    /**
     * @param array<string, mixed> $locals names bound by for blocks and layouts
     * @throws TemplateError
     */
    public static function render(Parsed $template, object $data, array $locals = []): string
    {
        return new self($template, $data)->nodes($template->nodes, $locals);
    }

    private function __construct(
        private Parsed $template,
        private object $data,
    ) {}

    /**
     * @param list<Node> $nodes
     * @param array<string, mixed> $locals
     * @throws TemplateError
     */
    private function nodes(array $nodes, array $locals): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match (true) {
                $node instanceof TextNode => $node->text,
                $node instanceof PrintNode => $this->print($node->path, $this->value($node->path, $locals)),
                $node instanceof IfNode => $this->ifNode($node, $locals),
                $node instanceof ForNode => $this->forNode($node, $locals),
                default => throw new TemplateError(sprintf('Unknown node %s.', $node::class), $this->template->name),
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $locals
     * @throws TemplateError
     */
    private function ifNode(IfNode $node, array $locals): string
    {
        foreach ($node->branches as [$condition, $body]) {
            if ($this->test($condition->path, $this->value($condition->path, $locals)) !== $condition->negated) {
                return $this->nodes($body, $locals);
            }
        }

        return $this->nodes($node->else, $locals);
    }

    /**
     * @param array<string, mixed> $locals
     * @throws TemplateError
     */
    private function forNode(ForNode $node, array $locals): string
    {
        $list = $this->value($node->list, $locals);
        if (!is_array($list) || !array_is_list($list)) {
            throw $this->error($node->list, sprintf('%s is %s; {%% for %%} iterates over lists.', $node->list->toString(), get_debug_type($list)));
        }
        if ($list === []) {
            return $this->nodes($node->else, $locals);
        }
        $out = '';
        $last = count($list) - 1;
        foreach ($list as $index => $item) {
            $out .= $this->nodes($node->body, [...$locals, $node->variable => $item, 'loop' => new Loop($index + 1, $index === 0, $index === $last)]);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $locals
     * @throws TemplateError
     */
    private function value(Path $path, array $locals): mixed
    {
        [$first] = $path->segments;
        if (array_key_exists($first, $locals)) {
            $value = $locals[$first];
        } else {
            $value = $this->property($this->data, $first, $path, $first);
        }
        $walked = $first;
        foreach (array_slice($path->segments, 1) as $segment) {
            if (!is_object($value)) {
                throw $this->error($path, sprintf('%s is %s, which has no property "%s".', $walked, get_debug_type($value), $segment));
            }
            $value = $this->property($value, $segment, $path, $walked . '.' . $segment);
            $walked .= '.' . $segment;
        }

        return $value;
    }

    /**
     * @throws TemplateError
     */
    private function property(object $object, string $name, Path $path, string $walked): mixed
    {
        if (!property_exists($object, $name)) {
            throw $this->error($path, sprintf('%s does not exist: %s has no property "%s".', $walked, $object::class, $name));
        }
        $property = new ReflectionProperty($object, $name);
        if (!$property->isPublic() || $property->isStatic()) {
            throw $this->error($path, sprintf('%s is not a public property of %s.', $walked, $object::class));
        }
        if (!$property->isInitialized($object)) {
            throw $this->error($path, sprintf('%s is not initialized.', $walked));
        }

        return $property->getValue($object);
    }

    /**
     * @throws TemplateError
     */
    private function print(Path $path, mixed $value): string
    {
        if ($value instanceof Html) {
            if (!$this->template->html) {
                throw $this->error($path, sprintf('%s is Html, which a text template cannot print.', $path->toString()));
            }

            return $value->markup;
        }
        $text = match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            $value instanceof Stringable => (string) $value,
            default => throw $this->error($path, sprintf(
                '%s is %s; templates print string, int, Stringable and Html, so format other values in PHP.',
                $path->toString(),
                get_debug_type($value),
            )),
        };

        return $this->template->html ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') : $text;
    }

    /**
     * @throws TemplateError
     */
    private function test(Path $path, mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === null => false,
            is_string($value) => $value !== '',
            is_array($value) && array_is_list($value) => $value !== [],
            default => throw $this->error($path, sprintf(
                '%s is %s; conditions test booleans, strings, lists and null.',
                $path->toString(),
                get_debug_type($value),
            )),
        };
    }

    private function error(Path $path, string $message): TemplateError
    {
        return new TemplateError($message, $this->template->name, $path->at->line, $path->at->column);
    }
}
