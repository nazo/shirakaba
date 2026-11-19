<?php

declare(strict_types=1);

namespace Shirakaba\Template;

use Closure;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Output;

/**
 * Registers `templates:check` (design/12-mail-and-templates.md), which parses every template and checks the declared
 * ones against their data classes; it exits with 1 when it finds a problem, for CI:
 *
 *     TemplateCommands::register($cli, static fn (): TemplateDirectory => $directory, [
 *         'mail/welcome' => Welcome::class,
 *     ]);
 */
final readonly class TemplateCommands
{
    /**
     * @param Closure(): TemplateDirectory $directory
     * @param array<string, class-string> $declared template name (without extension) => data class
     */
    public static function register(ConsoleBuilder $cli, Closure $directory, array $declared = [], string $prefix = ''): void
    {
        $cli->command(
            $prefix . 'templates:check',
            static function (Output $output) use ($directory, $declared): int {
                $problems = new TemplateChecker($directory())->check($declared);
                foreach ($problems as $problem) {
                    $output->line($problem);
                }
                if ($problems !== []) {
                    return 1;
                }
                $output->line('Every template parses and fits its data.');

                return 0;
            },
            'Parses every template and checks the declared ones against their data classes.',
        );
    }
}
