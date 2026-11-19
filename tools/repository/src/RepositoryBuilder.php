<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Repository;

use JsonException;

/**
 * Builds a static Composer repository from the packages in the monorepo (design/01-principles.md).
 *
 * Every release tag (`v1.2.3`, `v0.1.0-beta.1`) and the branch become versions of every
 * package under `packages/` at that commit. Each version is a zip of the package directory,
 * made with `git archive` and the commit time as the time of every entry, so the same commit
 * always gives the same archive and checksum and lock files stay valid across rebuilds.
 *
 * The output directory gets `packages.json`, the archives under `dist/` and an `index.html`.
 */
final readonly class RepositoryBuilder
{
    private const string TAG_PATTERN = '/^v(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/';

    private Git $git;

    public function __construct(
        string $repository,
        private string $baseUrl,
        private string $branch = 'main',
    ) {
        $this->git = new Git($repository);
    }

    /**
     * @return array<string, list<string>> versions written per package name
     * @throws RepositoryFailure when git fails, a manifest is invalid or a file cannot be written
     */
    public function build(string $output): array
    {
        $metadata = [];
        foreach ($this->versions() as $version => $ref) {
            foreach ($this->packagesAt($ref, $version, $output) as $name => $entry) {
                $metadata[$name][$version] = $entry;
            }
        }
        if ($metadata === []) {
            throw new RepositoryFailure('No packages were found at any version.');
        }
        ksort($metadata);
        foreach ($metadata as &$versions) {
            // Releases in order, then the branch.
            uksort(
                $versions,
                static fn (int|string $a, int|string $b): int => (
                    [str_starts_with((string) $a, 'dev-'), version_compare((string) $a, (string) $b)]
                    <=> [str_starts_with((string) $b, 'dev-'), 0]
                ),
            );
        }
        unset($versions);

        $this->write($output . '/packages.json', $this->json(['packages' => $metadata]) . "\n");
        $this->write($output . '/index.html', $this->index($metadata));

        return array_map(
            static fn (array $versions): array => array_map('strval', array_keys($versions)),
            $metadata,
        );
    }

    /**
     * @return array<string, string> commit per version: release tags, then the branch as dev-<branch>
     */
    private function versions(): array
    {
        $versions = [];
        foreach ($this->git->lines(['tag', '--list', 'v*']) as $tag) {
            if (preg_match(self::TAG_PATTERN, $tag, $match) === 1) {
                $versions[$match[1]] = $this->commit('refs/tags/' . $tag);
            }
        }
        $versions['dev-' . $this->branch] = $this->commit('refs/heads/' . $this->branch);

        return $versions;
    }

    private function commit(string $ref): string
    {
        return trim($this->git->run(['rev-parse', '--verify', $ref . '^{commit}']));
    }

    /**
     * @return array<string, array<string, mixed>> metadata per package name
     */
    private function packagesAt(string $commit, string $version, string $output): array
    {
        $time = trim($this->git->run(['show', '-s', '--format=%cI', $commit]));
        $packages = [];
        foreach ($this->git->lines(['ls-tree', '-d', '--name-only', $commit, 'packages/']) as $directory) {
            $manifest = $this->manifest($commit, $directory);
            if ($manifest === null) {
                continue;
            }
            [$name, $manifest] = $manifest;
            $file = sprintf('dist/%s/%s-%s.zip', $name, $version, substr($commit, 0, 12));
            $this->archive($commit, $directory, $time, $output . '/' . $file);
            $shasum = sha1_file($output . '/' . $file);
            if ($shasum === false) {
                throw new RepositoryFailure(sprintf('%s cannot be read.', $file));
            }
            $packages[$name] = [
                ...$manifest,
                'version' => $version,
                'time' => $time,
                'dist' => [
                    'type' => 'zip',
                    'url' => $this->baseUrl . $file,
                    'reference' => $commit,
                    'shasum' => $shasum,
                ],
            ];
        }

        return $packages;
    }

    /**
     * @return array{string, array<string, mixed>}|null the name and the manifest without a version, null
     *                                                 when the directory has no composer.json
     */
    private function manifest(string $commit, string $directory): ?array
    {
        $path = $directory . '/composer.json';
        if ($this->git->lines(['ls-tree', '--name-only', $commit, $path]) === []) {
            return null;
        }
        try {
            $data = json_decode($this->git->run(['show', $commit . ':' . $path]), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RepositoryFailure(sprintf('%s at %s is not valid JSON: %s', $path, $commit, $e->getMessage()), previous: $e);
        }
        if (
            !is_array($data)
            || !isset($data['name'])
            || !is_string($data['name'])
            || preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $data['name']) !== 1
        ) {
            throw new RepositoryFailure(sprintf('%s at %s has no valid package name.', $path, $commit));
        }
        $manifest = [];
        foreach ($data as $key => $value) {
            if ($key !== 'version') {
                $manifest[(string) $key] = $value;
            }
        }

        return [$data['name'], $manifest];
    }

    private function archive(string $commit, string $directory, string $time, string $file): void
    {
        $this->directory(dirname($file));
        $this->git->run([
            'archive',
            '--format=zip',
            '--mtime=' . $time,
            '--output=' . $file,
            $commit . ':' . $directory,
        ]);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $metadata
     */
    private function index(array $metadata): string
    {
        $repository = $this->json(['repositories' => [['type' => 'composer', 'url' => $this->baseUrl]]]);
        $rows = '';
        foreach ($metadata as $name => $versions) {
            $rows .= sprintf(
                "<tr><td><code>%s</code></td><td>%s</td></tr>\n",
                htmlspecialchars($name),
                htmlspecialchars(implode(', ', array_reverse(array_map('strval', array_keys($versions))))),
            );
        }

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Shirakaba packages</title>
            <h1>Shirakaba packages</h1>
            <p>A Composer repository for the Shirakaba packages, which are not on Packagist.
            Add it to your application's <code>composer.json</code>:</p>
            <pre>{$this->escape($repository)}</pre>
            <table>
            <tr><th>Package</th><th>Versions</th></tr>
            {$rows}</table>

            HTML;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        try {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RepositoryFailure('The metadata cannot be encoded: ' . $e->getMessage(), previous: $e);
        }
    }

    private function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, recursive: true) && !is_dir($path)) {
            throw new RepositoryFailure(sprintf('%s cannot be created.', $path));
        }
    }

    private function write(string $file, string $contents): void
    {
        $this->directory(dirname($file));
        if (file_put_contents($file, $contents) === false) {
            throw new RepositoryFailure(sprintf('%s cannot be written.', $file));
        }
    }
}
