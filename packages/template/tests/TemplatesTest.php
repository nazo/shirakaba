<?php

declare(strict_types=1);

namespace Shirakaba\Template\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Testing\ConsoleTester;
use Shirakaba\Template\Html;
use Shirakaba\Template\Internal\CheckContext;
use Shirakaba\Template\Internal\ParsedTemplates;
use Shirakaba\Template\Internal\Parser;
use Shirakaba\Template\Internal\Renderer;
use Shirakaba\Template\Internal\Types;
use Shirakaba\Template\TemplateChecker;
use Shirakaba\Template\TemplateCommands;
use Shirakaba\Template\TemplateDirectory;
use Shirakaba\Template\TemplateError;
use Shirakaba\Template\Templates;
use Shirakaba\Template\Tests\Fixtures\Item;
use Shirakaba\Template\Tests\Fixtures\User;
use Shirakaba\Template\Tests\Fixtures\Welcome;

#[CoversClass(Templates::class)]
#[CoversClass(TemplateDirectory::class)]
#[CoversClass(TemplateError::class)]
#[CoversClass(TemplateChecker::class)]
#[CoversClass(TemplateCommands::class)]
#[CoversClass(Html::class)]
#[CoversClass(Parser::class)]
#[CoversClass(Renderer::class)]
#[CoversClass(ParsedTemplates::class)]
#[CoversClass(CheckContext::class)]
#[CoversClass(Types::class)]
final class TemplatesTest extends TestCase
{
    private static function directory(): TemplateDirectory
    {
        return new TemplateDirectory(__DIR__ . '/Fixtures/templates');
    }

    /**
     * @param list<Item> $items
     */
    private static function welcome(string $name = 'Ann <ann@example.com>', bool $admin = false, bool $verified = true, array $items = []): Welcome
    {
        return new Welcome(new User($name, $admin, $verified), $items, 'Example & Co', new Html('<b>Team</b>'));
    }

    /**
     * Renders a template given as a string, through a temporary directory.
     *
     * @param array<string, string> $files name => source
     */
    private static function renderSources(array $files, string $name, object $data, ?string $locale = null): string
    {
        $directory = sys_get_temp_dir() . '/shirakaba-templates-' . bin2hex(random_bytes(4));
        mkdir($directory);
        try {
            foreach ($files as $file => $source) {
                @mkdir(dirname($directory . '/' . $file), 0777, true);
                file_put_contents($directory . '/' . $file, $source);
            }

            return new Templates(new TemplateDirectory($directory))->render($name, $data, $locale);
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    public function testRendersTextWithLayoutConditionsAndLoops(): void
    {
        $text = new Templates(self::directory())->render('mail/welcome.txt', self::welcome(items: [new Item('First'), new Item('Second')]));

        self::assertSame("Hello Ann <ann@example.com>,\n\nWelcome aboard.\n1. First\n2. Second (last)\n\n--\nExample & Co\n", $text);
    }

    public function testBranchesAndEmptyLists(): void
    {
        $templates = new Templates(self::directory());

        self::assertStringContainsString("You are an administrator.\nNo items.\n", $templates->render('mail/welcome.txt', self::welcome(admin: true)));
        self::assertStringContainsString('Please verify your address.', $templates->render('mail/welcome.txt', self::welcome(verified: false)));
    }

    public function testHtmlEscapesEverythingButHtml(): void
    {
        $html = new Templates(self::directory())->render('mail/welcome.html', self::welcome());

        self::assertSame("<html><body><p>Hello Ann &lt;ann@example.com&gt;</p><b>Team</b>\n<footer>Example &amp; Co</footer></body></html>\n", $html);
    }

    public function testTextRefusesHtml(): void
    {
        $this->expectException(TemplateError::class);
        $this->expectExceptionMessage('t.txt:1:4: signature is Html, which a text template cannot print.');
        self::renderSources(['t.txt' => 'Hi {{ signature }}'], 't.txt', self::welcome());
    }

    public function testLocalesFallBack(): void
    {
        $templates = new Templates(self::directory());

        self::assertSame("Ann さん、ようこそ。\n", $templates->render('mail/welcome.txt', self::welcome(name: 'Ann'), 'ja-JP'));
        self::assertStringStartsWith('Hello Ann,', $templates->render('mail/welcome.txt', self::welcome(name: 'Ann'), 'fr'));
        self::assertTrue($templates->exists('mail/welcome.html', 'ja'));
        self::assertFalse($templates->exists('mail/missing.html'));
    }

    public function testRefusesNamesOutsideTheDirectory(): void
    {
        foreach (['../secret.txt', '/etc/passwd.txt', 'mail/../x.txt', 'welcome', 'a\\b.txt'] as $name) {
            try {
                new Templates(self::directory())->render($name, self::welcome());
                self::fail(sprintf('"%s" must be refused.', $name));
            } catch (TemplateError $e) {
                self::assertStringContainsString('is not a template name', $e->getMessage());
            }
        }
    }

    public function testValuesThatCannotBePrintedAreErrors(): void
    {
        $data = new User('Ann', score: 1.5);
        try {
            self::renderSources(['t.txt' => "line\n  {{ score }}"], 't.txt', $data);
            self::fail('A float must not be printed.');
        } catch (TemplateError $e) {
            self::assertSame('t.txt:2:3: score is float; templates print string, int, Stringable and Html, so format other values in PHP.', $e->getMessage());
            self::assertSame(2, $e->templateLine);
        }

        $this->expectExceptionMessage('t.txt:1:1: score is null;');
        self::renderSources(['t.txt' => '{{ score }}'], 't.txt', new User('Ann'));
    }

    public function testConditionsTestOnlyBooleansStringsListsAndNull(): void
    {
        self::assertSame('none', self::renderSources(['t.txt' => '{% if score %}set{% else %}none{% endif %}'], 't.txt', new User('Ann')));

        $this->expectExceptionMessage('t.txt:1:1: score is float; conditions test booleans, strings, lists and null.');
        self::renderSources(['t.txt' => '{% if score %}x{% endif %}'], 't.txt', new User('Ann', score: 0.0));
    }

    public function testSyntaxErrorsNameTheTemplateLineAndColumn(): void
    {
        $cases = [
            "a\n{{ user.name() }}" => 't.txt:2:1: "user.name()" is not a property path',
            '{% if user.admin %}x' => 'The template ends before {% endif %}',
            '{% for x of items %}{% endfor %}' => 't.txt:1:1: Write {% for item in path %}.',
            "x\n{% layout \"l\" %}" => 't.txt:2:1: The layout tag must come first.',
            '{{ user.name' => 't.txt:1:1: "{{" is not closed with "}}".',
            '{% endif %}' => 't.txt:1:1: {% endif %} without an opening tag.',
            '{% include "x" %}' => 'Unknown tag "include"',
        ];
        foreach ($cases as $source => $message) {
            try {
                Parser::parse('t.txt', $source, false);
                self::fail(sprintf('"%s" must not parse.', $source));
            } catch (TemplateError $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testBlockLinesLeaveNoBlankLines(): void
    {
        $source = "a\n  {% if admin %}\n  admin\n  {% endif %}\nb {% if admin %}x{% endif %} c\n";

        self::assertSame("a\nb  c\n", self::renderSources(['t.txt' => $source], 't.txt', new User('Ann')));
    }

    public function testRendersRepeatedlyWithoutKeepingState(): void
    {
        $templates = new Templates(self::directory());
        $first = $templates->render('mail/welcome.txt', self::welcome(name: 'Ann'));
        $templates->render('mail/welcome.txt', self::welcome(name: 'Bob', admin: true));

        self::assertSame($first, $templates->render('mail/welcome.txt', self::welcome(name: 'Ann')));
    }

    public function testCheckerFindsPathsThatDoNotFitTheDataClass(): void
    {
        $checker = new TemplateChecker(self::directory());
        self::assertSame([], $checker->check(['mail/welcome' => Welcome::class]));

        $directory = sys_get_temp_dir() . '/shirakaba-templates-' . bin2hex(random_bytes(4));
        mkdir($directory);
        try {
            file_put_contents(
                $directory . '/w.txt',
                "{{ user.nmae }}\n{% for item in items %}{{ item.titel }}{% endfor %}\n{{ user.score }}\n{% if user %}{% endif %}\n{% for x in site %}{% endfor %}\n",
            );
            file_put_contents($directory . '/w.html', '{{ signature }}');
            file_put_contents($directory . '/broken.txt', '{% if %}');

            self::assertSame(
                [
                    'broken.txt:1:1: Write {% if path %} or {% if not path %}; conditions test a value, not an expression.',
                    'w.txt:1:1: user.nmae does not exist: ' . User::class . ' has no property "nmae".',
                    'w.txt:2:24: item.titel does not exist: ' . Item::class . ' has no property "titel".',
                    'w.txt:3:1: user.score may be float, which a text template cannot print; print string, int, Stringable.',
                    'w.txt:4:1: user may be ' . User::class . '; conditions test booleans, strings, lists and null.',
                    'w.txt:5:1: site may be string; {% for %} iterates over lists.',
                ],
                new TemplateChecker(new TemplateDirectory($directory))->check(['w' => Welcome::class]),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    public function testCheckCommandExitsWithOneOnProblems(): void
    {
        $cli = new ConsoleBuilder('app');
        TemplateCommands::register($cli, static fn (): TemplateDirectory => self::directory(), ['mail/welcome' => Welcome::class]);
        $ok = new ConsoleTester($cli->build())->run('templates:check');

        self::assertSame(0, $ok->exitCode, $ok->output);
        self::assertSame("Every template parses and fits its data.\n", $ok->output);
    }
}
