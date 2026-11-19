<?php

declare(strict_types=1);

namespace Shirakaba\Docs\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Testing\ConsoleTester;
use Shirakaba\Docs\AgentCommands;
use Shirakaba\Docs\AgentRules;

#[CoversClass(AgentCommands::class)]
#[CoversClass(AgentRules::class)]
final class AgentCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shirakaba-docs-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $files = glob($this->root . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    private function tester(): ConsoleTester
    {
        $cli = new ConsoleBuilder('shirakaba-docs');
        AgentCommands::register($cli, $this->root);

        return new ConsoleTester($cli->build());
    }

    private function read(string $name): string
    {
        return (string) file_get_contents($this->root . '/' . $name);
    }

    public function testCreatesAgentsMdWithTheBlock(): void
    {
        $result = $this->tester()->run('agents:sync');

        self::assertSame(0, $result->exitCode);
        $agents = $this->read('AGENTS.md');
        self::assertStringStartsWith(AgentRules::BEGIN, $agents);
        self::assertStringContainsString('vendor/shirakaba/docs/index.md', $agents);
        self::assertStringEndsWith(AgentRules::END . "\n", $agents);
        self::assertFileDoesNotExist($this->root . '/CLAUDE.md');
    }

    public function testKeepsTheProjectsOwnTextAndReplacesOnlyTheBlock(): void
    {
        file_put_contents(
            $this->root . '/AGENTS.md',
            "# Shop\n\nUse tabs.\n\n" . AgentRules::BEGIN . "\nold rules\n" . AgentRules::END . "\n\n## Deploy\n\nAsk first.\n",
        );

        self::assertSame(0, $this->tester()->run('agents:sync')->exitCode);

        $agents = $this->read('AGENTS.md');
        self::assertStringStartsWith("# Shop\n\nUse tabs.\n\n" . AgentRules::BEGIN, $agents);
        self::assertStringEndsWith(AgentRules::END . "\n\n## Deploy\n\nAsk first.\n", $agents);
        self::assertStringNotContainsString('old rules', $agents);
        self::assertSame(1, substr_count($agents, AgentRules::BEGIN));
    }

    public function testAppendsTheBlockToAFileWithoutOne(): void
    {
        file_put_contents($this->root . '/AGENTS.md', "# Shop\n");

        $this->tester()->run('agents:sync');

        self::assertStringStartsWith("# Shop\n\n" . AgentRules::BEGIN, $this->read('AGENTS.md'));
    }

    public function testRunningTwiceChangesNothing(): void
    {
        $this->tester()->run('agents:sync', '--claude');
        $agents = $this->read('AGENTS.md');
        $claude = $this->read('CLAUDE.md');

        $second = $this->tester()->run('agents:sync', '--claude');

        self::assertSame($agents, $this->read('AGENTS.md'));
        self::assertSame($claude, $this->read('CLAUDE.md'));
        self::assertStringContainsString('already up to date', $second->output);
    }

    public function testClaudeImportsAgentsMdOnce(): void
    {
        file_put_contents($this->root . '/CLAUDE.md', "# Notes\n");

        $result = $this->tester()->run('agents:sync', '--claude');

        self::assertSame(0, $result->exitCode);
        self::assertSame("# Notes\n\n@AGENTS.md\n", $this->read('CLAUDE.md'));
    }

    public function testCheckFailsWhenTheBlockIsMissingOrOutdatedAndWritesNothing(): void
    {
        $missing = $this->tester()->run('agents:sync', '--check');
        self::assertSame(1, $missing->exitCode);
        self::assertStringContainsString('not up to date', $missing->errors);
        self::assertFileDoesNotExist($this->root . '/AGENTS.md');

        file_put_contents($this->root . '/AGENTS.md', AgentRules::BEGIN . "\nold\n" . AgentRules::END . "\n");
        self::assertSame(1, $this->tester()->run('agents:sync', '--check')->exitCode);

        $this->tester()->run('agents:sync');
        self::assertSame(0, $this->tester()->run('agents:sync', '--check')->exitCode);
        self::assertSame(1, $this->tester()->run('agents:sync', '--check', '--claude')->exitCode);
    }

    public function testWritesAnotherFileAndDocsPath(): void
    {
        $this->tester()->run('agents:sync', '--file', 'CONVENTIONS.md', '--docs', 'lib/shirakaba-docs');

        self::assertStringContainsString('`lib/shirakaba-docs/index.md`', $this->read('CONVENTIONS.md'));
    }
}
