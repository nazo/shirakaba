<?php

declare(strict_types=1);

namespace Shirakaba\Docs;

/**
 * The block shirakaba-docs agents:sync keeps in a project's AGENTS.md (design/13-static-analysis-and-agents.md). Only the text
 * between the markers belongs to Shirakaba; everything else in the file is the project's.
 */
final readonly class AgentRules
{
    public const string BEGIN = '<!-- BEGIN:shirakaba-agent-rules -->';
    public const string END = '<!-- END:shirakaba-agent-rules -->';

    public static function block(string $version, string $docs): string
    {
        return implode("\n", [
            self::BEGIN,
            '',
            '## Shirakaba is not in your training data',
            '',
            'This project uses Shirakaba, a PHP framework your training data does not cover. Before writing',
            sprintf('code, read `%s/index.md` (where to look, and the rules agents most often get', $docs),
            sprintf('wrong) and check classes in `%s/api.md`. Do not guess APIs from Laravel, Symfony', $docs),
            'or other frameworks.',
            '',
            sprintf('Installed documentation: shirakaba/docs %s.', $version),
            '',
            'Before finishing, run:',
            '',
            '- PHPStan with `shirakaba/phpstan` included, and the tests;',
            '- `openapi --strict` when routes changed;',
            '- `db:diff --check` and `db:lint` when entities or migrations changed.',
            '',
            'The command names are the ones this application registers its console commands under.',
            '',
            'This block is written by `vendor/bin/shirakaba-docs agents:sync`; run it again after',
            'upgrading Shirakaba. Text outside the markers is never changed.',
            '',
            self::END,
        ]);
    }

    /**
     * The file with the block inserted, or replaced when present.
     */
    public static function apply(string $file, string $block): string
    {
        $begin = strpos($file, self::BEGIN);
        $end = strpos($file, self::END);
        if ($begin !== false && $end !== false && $end > $begin) {
            return substr($file, 0, $begin) . $block . substr($file, $end + strlen(self::END));
        }
        if (trim($file) === '') {
            return $block . "\n";
        }

        return rtrim($file) . "\n\n" . $block . "\n";
    }

    /**
     * The installed version of shirakaba/docs, as Composer reports it.
     */
    public static function installedVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('shirakaba/docs')) {
            return \Composer\InstalledVersions::getPrettyVersion('shirakaba/docs') ?? 'dev';
        }

        return 'dev';
    }
}
