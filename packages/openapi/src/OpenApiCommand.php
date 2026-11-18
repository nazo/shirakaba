<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi;

use Closure;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Option;
use Shirakaba\Console\Output;
use Shirakaba\Core\AppBuilder;

/**
 * Registers a command writing the application's OpenAPI document (design/06-json-and-openapi.md and design/10-background-work.md):
 *
 *     OpenApiCommand::register($cli, static fn (): AppBuilder => App::builder(...), $generator);
 *     // app openapi --output=openapi.json --strict
 */
final readonly class OpenApiCommand
{
    /**
     * @param Closure(): AppBuilder $app
     * @param Closure(): Generator $generator
     */
    public static function register(ConsoleBuilder $cli, Closure $app, Closure $generator, string $name = 'openapi'): void
    {
        $cli->command($name, static fn (
            Output $out,
            #[Option('File to write; standard output when omitted')] ?string $output = null,
            #[Option('Exit with 1 when anything could not be described')] bool $strict = false,
        ): int => self::write($app(), $generator(), $output, $strict, $out), 'Writes the OpenAPI document of the application.');
    }

    /**
     * Generates the document, prints warnings to standard error, and writes it.
     *
     * @return int 0, or 1 for warnings in strict mode or a failed write
     */
    public static function write(AppBuilder $app, Generator $generator, ?string $file, bool $strict, Output $output): int
    {
        $document = $generator->generate($app->describe());
        foreach ($document->warnings as $warning) {
            $output->error('warning: ' . $warning);
        }
        if ($file === null) {
            $output->write($document->toJson());
        } elseif (file_put_contents($file, $document->toJson()) === false) {
            $output->error(sprintf('Unable to write %s.', $file));

            return 1;
        }

        return $strict && $document->warnings !== [] ? 1 : 0;
    }
}
