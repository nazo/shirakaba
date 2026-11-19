<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

use Shirakaba\Template\TemplateError;

/**
 * Parses the syntax of design/12-mail-and-templates.md into an immutable tree:
 *
 *     {{ path }}   {% if [not] path %} {% elseif [not] path %} {% else %} {% endif %}
 *     {% for item in path %} {% else %} {% endfor %}   {% layout "name" %}   {# comment #}
 *
 * A block tag or comment alone on its line takes the whole line with it, so text templates
 * do not fill with blank lines.
 *
 * @internal
 */
final class Parser
{
    private const string NAME = '[A-Za-z_][A-Za-z0-9_]*';

    /** @var list<array{string, string, Position}> kind ("text", "print", "block"), content, position */
    private array $tokens = [];
    private int $index = 0;

    private function __construct(
        private readonly string $name,
    ) {}

    /**
     * @throws TemplateError
     */
    public static function parse(string $name, string $source, bool $html): Parsed
    {
        $parser = new self($name);
        $parser->tokenize($source);
        $layout = $parser->layout();
        $nodes = $parser->nodes([]);

        return new Parsed($name, $html, $nodes, $layout);
    }

    /**
     * @throws TemplateError
     */
    private function tokenize(string $source): void
    {
        $offset = 0;
        $length = strlen($source);
        while ($offset < $length) {
            $start = self::nextTag($source, $offset);
            if ($start === null) {
                $this->tokens[] = ['text', substr($source, $offset), $this->position($source, $offset)];
                break;
            }
            $opener = substr($source, $start, 2);
            $closer = match ($opener) {
                '{{' => '}}',
                '{%' => '%}',
                default => '#}',
            };
            $end = strpos($source, $closer, $start + 2);
            if ($end === false) {
                throw $this->errorAt($source, $start, sprintf('"%s" is not closed with "%s".', $opener, $closer));
            }
            $text = substr($source, $offset, $start - $offset);
            $after = $end + 2;
            if ($opener !== '{{') {
                // A block tag or comment alone on its line removes the line.
                $lineStart = strrpos($text, "\n");
                $indent = $lineStart === false ? $text : substr($text, $lineStart + 1);
                $atLineStart =
                    ($lineStart !== false || $offset === 0 || $this->tokens !== [] && str_ends_with($this->lastText(), "\n")) && trim($indent, " \t") === '';
                $rest = substr($source, $after);
                if ($atLineStart && preg_match('/\A[ \t]*(\r?\n|\z)/', $rest, $match) === 1) {
                    $text = substr($text, 0, strlen($text) - strlen($indent));
                    $after += strlen($match[0]);
                }
            }
            if ($text !== '') {
                $this->tokens[] = ['text', $text, $this->position($source, $offset)];
            }
            if ($opener !== '{#') {
                $this->tokens[] = [
                    $opener === '{{' ? 'print' : 'block',
                    trim(substr($source, $start + 2, $end - $start - 2)),
                    $this->position($source, $start),
                ];
            }
            $offset = $after;
        }
    }

    /**
     * @return array{string, string, Position}
     * @throws TemplateError at the end of the template
     */
    private function token(int $index): array
    {
        return $this->tokens[$index] ?? throw new TemplateError('The template ends inside a block.', $this->name, 0, 0);
    }

    private function lastText(): string
    {
        foreach (array_reverse($this->tokens) as [$kind, $content]) {
            if ($kind === 'text') {
                return $content;
            }
        }

        return "\n";
    }

    private static function nextTag(string $source, int $offset): ?int
    {
        $found = null;
        foreach (['{{', '{%', '{#'] as $opener) {
            $position = strpos($source, $opener, $offset);
            if ($position !== false && ($found === null || $position < $found)) {
                $found = $position;
            }
        }

        return $found;
    }

    /**
     * A layout tag must come before any output.
     *
     * @throws TemplateError
     */
    private function layout(): ?string
    {
        $layout = null;
        $layoutIndex = null;
        foreach ($this->tokens as $index => [$kind, $content, $at]) {
            if ($kind === 'block' && preg_match('/\Alayout\s+"([A-Za-z0-9_\/-]+)"\z/', $content, $match) === 1) {
                if ($layout !== null) {
                    throw $this->error($at, 'A template has one layout.');
                }
                foreach (array_slice($this->tokens, 0, $index) as [$beforeKind, $beforeContent]) {
                    if ($beforeKind !== 'text' || trim($beforeContent) !== '') {
                        throw $this->error($at, 'The layout tag must come first.');
                    }
                }
                $layout = $match[1];
                $layoutIndex = $index;
            } elseif ($kind === 'block' && str_starts_with($content, 'layout')) {
                throw $this->error($at, 'Write the layout as {% layout "name" %}.');
            }
        }
        if ($layoutIndex !== null) {
            array_splice($this->tokens, $layoutIndex, 1);
        }
        if ($layout !== null) {
            // Text before the layout tag is whitespace; drop it.
            while ($this->tokens !== [] && $this->tokens[0][0] === 'text' && trim($this->tokens[0][1]) === '') {
                array_shift($this->tokens);
            }
        }

        return $layout;
    }

    /**
     * Parses nodes until one of $until (block keywords) is reached; that token is left unread.
     *
     * @param list<string> $until
     * @return list<Node>
     * @throws TemplateError
     */
    private function nodes(array $until): array
    {
        $nodes = [];
        while ($this->index < count($this->tokens)) {
            [$kind, $content, $at] = $this->token($this->index);
            if ($kind === 'text') {
                $nodes[] = new TextNode($content);
                $this->index++;
                continue;
            }
            if ($kind === 'print') {
                $nodes[] = new PrintNode($this->path($content, $at));
                $this->index++;
                continue;
            }
            $keyword = (string) strtok($content, " \t");
            if (in_array($keyword, $until, true)) {
                return $nodes;
            }
            $nodes[] = match ($keyword) {
                'if' => $this->ifNode(),
                'for' => $this->forNode(),
                'elseif', 'else', 'endif', 'endfor' => throw $this->error($at, sprintf('{%% %s %%} without an opening tag.', $keyword)),
                default => throw $this->error($at, sprintf('Unknown tag "%s"; the tags are if, elseif, else, endif, for, endfor and layout.', $keyword)),
            };
        }
        if ($until !== []) {
            throw new TemplateError(sprintf('The template ends before {%% %s %%}.', $until[array_key_last($until)]), $this->name, 0, 0);
        }

        return $nodes;
    }

    /**
     * @throws TemplateError
     */
    private function ifNode(): IfNode
    {
        $branches = [[$this->condition('if'), $this->nodes(['elseif', 'else', 'endif'])]];
        $else = [];
        while (true) {
            [, $content, $at] = $this->token($this->index);
            $keyword = strtok($content, " \t");
            $this->index++;
            if ($keyword === 'elseif') {
                $this->index--;
                $branches[] = [$this->condition('elseif'), $this->nodes(['elseif', 'else', 'endif'])];
                continue;
            }
            if ($keyword === 'else') {
                $this->expectBare($content, 'else', $at);
                $else = $this->nodes(['endif']);
                [, $end, $endAt] = $this->token($this->index);
                $this->expectBare($end, 'endif', $endAt);
                $this->index++;
            } else {
                $this->expectBare($content, 'endif', $at);
            }

            return new IfNode($branches, $else);
        }
    }

    /**
     * Reads the condition of the current if/elseif tag and moves past it.
     *
     * @throws TemplateError
     */
    private function condition(string $keyword): Condition
    {
        [, $content, $at] = $this->token($this->index);
        $this->index++;
        if (preg_match('/\A' . $keyword . '\s+(not\s+)?(\S+)\z/', $content, $match) !== 1) {
            throw $this->error($at, sprintf('Write {%% %s path %%} or {%% %s not path %%}; conditions test a value, not an expression.', $keyword, $keyword));
        }

        return new Condition($this->path($match[2] ?? '', $at), ($match[1] ?? '') !== '');
    }

    /**
     * @throws TemplateError
     */
    private function forNode(): ForNode
    {
        [, $content, $at] = $this->token($this->index);
        $this->index++;
        if (preg_match('/\Afor\s+(' . self::NAME . ')\s+in\s+(\S+)\z/', $content, $match) !== 1) {
            throw $this->error($at, 'Write {% for item in path %}.');
        }
        $variable = $match[1];
        if ($variable === 'loop') {
            throw $this->error($at, '"loop" is reserved for the loop\'s position.');
        }
        $list = $this->path($match[2], $at);
        $body = $this->nodes(['else', 'endfor']);
        [, $next, $nextAt] = $this->token($this->index);
        $this->index++;
        $else = [];
        if (strtok($next, " \t") === 'else') {
            $this->expectBare($next, 'else', $nextAt);
            $else = $this->nodes(['endfor']);
            [, $end, $endAt] = $this->token($this->index);
            $this->expectBare($end, 'endfor', $endAt);
            $this->index++;
        } else {
            $this->expectBare($next, 'endfor', $nextAt);
        }

        return new ForNode($variable, $list, $body, $else);
    }

    /**
     * @throws TemplateError
     */
    private function expectBare(string $content, string $keyword, Position $at): void
    {
        if ($content !== $keyword) {
            throw $this->error($at, sprintf('{%% %s %%} takes nothing else.', $keyword));
        }
    }

    /**
     * @throws TemplateError
     */
    private function path(string $text, Position $at): Path
    {
        if (preg_match('/\A' . self::NAME . '(\.' . self::NAME . ')*\z/', $text) !== 1) {
            throw $this->error($at, sprintf(
                '"%s" is not a property path; templates print and test paths such as user.name, without calls, filters or arithmetic.',
                $text,
            ));
        }
        $segments = explode('.', $text);

        return new Path($segments, $at);
    }

    private function position(string $source, int $offset): Position
    {
        $before = substr($source, 0, $offset);
        $newline = strrpos($before, "\n");

        return new Position(substr_count($before, "\n") + 1, $offset - ($newline === false ? 0 : $newline + 1) + 1);
    }

    private function errorAt(string $source, int $offset, string $message): TemplateError
    {
        return $this->error($this->position($source, $offset), $message);
    }

    private function error(Position $at, string $message): TemplateError
    {
        return new TemplateError($message, $this->name, $at->line, $at->column);
    }
}
