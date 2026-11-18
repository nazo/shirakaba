<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Repository;

/**
 * Runs git in one repository, without a shell.
 */
final readonly class Git
{
    public function __construct(
        private string $directory,
    ) {}

    /**
     * @param list<string> $arguments
     * @throws RepositoryFailure when git exits with an error
     */
    public function run(array $arguments): string
    {
        $process = proc_open(
            ['git', '-C', $this->directory, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new RepositoryFailure('git cannot be started.');
        }
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (!is_resource($stdout) || !is_resource($stderr)) {
            proc_close($process);
            throw new RepositoryFailure('git cannot be started.');
        }
        $output = stream_get_contents($stdout);
        $error = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        $status = proc_close($process);
        if ($status !== 0 || $output === false) {
            throw new RepositoryFailure(sprintf(
                'git %s failed: %s',
                implode(' ', $arguments),
                trim(is_string($error) ? $error : ''),
            ));
        }

        return $output;
    }

    /**
     * @param list<string> $arguments
     * @return list<string> the non-empty lines of the output
     * @throws RepositoryFailure when git exits with an error
     */
    public function lines(array $arguments): array
    {
        return array_values(array_filter(
            explode("\n", $this->run($arguments)),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
