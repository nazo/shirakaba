<?php

declare(strict_types=1);

namespace Shirakaba\Docs;

use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Option;
use Shirakaba\Console\Output;

/**
 * Registers agents:sync (design/13-static-analysis-and-agents.md) in a console, for `shirakaba-docs` and an application's own
 * console.
 */
final readonly class AgentCommands
{
    /**
     * @param string $projectRoot where AGENTS.md and CLAUDE.md live
     */
    public static function register(ConsoleBuilder $cli, string $projectRoot, string $prefix = ''): void
    {
        $cli->command($prefix . 'agents:sync', static function (
            Output $output,
            #[Option('Exit with 1 when the block is missing or outdated, changing nothing')] bool $check = false,
            #[Option('Also make CLAUDE.md import AGENTS.md')] bool $claude = false,
            #[Option('The file to write, relative to the project root')] string $file = 'AGENTS.md',
            #[Option('Where shirakaba/docs is installed, relative to the project root')] string $docs = 'vendor/shirakaba/docs',
        ) use ($projectRoot): int {
            $path = rtrim($projectRoot, '/') . '/' . $file;
            $current = is_file($path) ? (string) file_get_contents($path) : '';
            $updated = AgentRules::apply($current, AgentRules::block(AgentRules::installedVersion(), $docs));
            $claudePath = rtrim($projectRoot, '/') . '/CLAUDE.md';
            $claudeCurrent = is_file($claudePath) ? (string) file_get_contents($claudePath) : '';
            $claudeUpdated = $claude && !str_contains($claudeCurrent, '@' . $file)
                ? ($claudeCurrent === '' ? '@' . $file . "\n" : rtrim($claudeCurrent) . "\n\n@" . $file . "\n")
                : $claudeCurrent;

            if ($check) {
                if ($updated !== $current || $claudeUpdated !== $claudeCurrent) {
                    $output->error(sprintf('%s is not up to date; run shirakaba-docs agents:sync.', $file));

                    return 1;
                }
                $output->line(sprintf('%s is up to date.', $file));

                return 0;
            }
            if ($updated !== $current) {
                file_put_contents($path, $updated);
                $output->line(sprintf('Updated the Shirakaba block in %s.', $file));
            } else {
                $output->line(sprintf('%s is already up to date.', $file));
            }
            if ($claudeUpdated !== $claudeCurrent) {
                file_put_contents($claudePath, $claudeUpdated);
                $output->line(sprintf('CLAUDE.md now imports %s.', $file));
            }

            return 0;
        }, 'Writes the block that points coding agents at the bundled Shirakaba docs into AGENTS.md.');
    }
}
