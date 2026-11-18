<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Repository\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Tools\Repository\Git;
use Shirakaba\Tools\Repository\RepositoryBuilder;
use Shirakaba\Tools\Repository\RepositoryFailure;
use ZipArchive;

#[CoversClass(RepositoryBuilder::class)]
#[CoversClass(Git::class)]
final class RepositoryBuilderTest extends TestCase
{
    private const string URL = 'https://example.test/repo/';

    private string $dir;

    private Git $git;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shirakaba-repository-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/source', recursive: true);
        $this->git = new Git($this->dir . '/source');
        $this->git->run(['init', '--quiet', '--initial-branch=main']);
        $this->git->run(['config', 'user.email', 'test@example.test']);
        $this->git->run(['config', 'user.name', 'Test']);
        $this->git->run(['config', 'commit.gpgsign', 'false']);
        $this->git->run(['config', 'tag.gpgsign', 'false']);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function testTagsAndTheBranchBecomeVersionsOfEveryPackage(): void
    {
        $this->package('core', ['name' => 'acme/core', 'require' => ['php' => '^8.5']]);
        $this->package('orm', ['name' => 'acme/orm', 'require' => ['acme/core' => '^0.1']]);
        $this->commit('First');
        $this->git->run(['tag', 'v0.1.0']);
        $this->file('packages/core/src/Added.php', '<?php');
        $this->commit('Second');
        $this->git->run(['tag', 'not-a-release']);

        $versions = $this->build('main');

        self::assertSame(['acme/core' => ['0.1.0', 'dev-main'], 'acme/orm' => ['0.1.0', 'dev-main']], $versions);
        $release = $this->entry('acme/core', '0.1.0');
        self::assertSame('0.1.0', $release['version'] ?? null);
        self::assertSame(['php' => '^8.5'], $release['require'] ?? null);
        self::assertSame(['branch-alias' => ['dev-main' => '0.1.x-dev']], $this->entry('acme/core', 'dev-main')['extra'] ?? null);
        $dist = $this->dist($release);
        self::assertSame('zip', $dist['type']);
        self::assertSame(trim($this->git->run(['rev-parse', 'v0.1.0^{commit}'])), $dist['reference']);
        self::assertStringStartsWith(self::URL . 'dist/acme/core/0.1.0-', $dist['url']);
        $file = $this->archive($dist['url']);
        self::assertSame(sha1_file($file), $dist['shasum']);
        self::assertSame(['composer.json', 'src/Core.php'], $this->entries($file));
        self::assertContains('src/Added.php', $this->entries($this->archive($this->dist($this->entry('acme/core', 'dev-main'))['url'])));
        self::assertStringContainsString('acme/core', (string) file_get_contents($this->dir . '/out/index.html'));
    }

    public function testRebuildingGivesTheSameArchives(): void
    {
        $this->package('core', ['name' => 'acme/core']);
        $this->commit('First');
        $this->git->run(['tag', 'v1.0.0']);

        $this->build('main', 'out');
        sleep(1);
        $this->build('main', 'again');

        self::assertSame(
            $this->dist($this->entry('acme/core', '1.0.0', 'out'))['shasum'],
            $this->dist($this->entry('acme/core', '1.0.0', 'again'))['shasum'],
        );
    }

    public function testDirectoriesWithoutAManifestAreSkipped(): void
    {
        $this->package('core', ['name' => 'acme/core']);
        $this->file('packages/notes/README.md', 'Not a package');
        $this->commit('First');

        self::assertSame(['acme/core' => ['dev-main']], $this->build('main'));
    }

    public function testAManifestWithoutANameIsRefused(): void
    {
        $this->package('core', ['description' => 'No name']);
        $this->commit('First');

        $this->expectException(RepositoryFailure::class);
        $this->expectExceptionMessage('has no valid package name');
        $this->build('main');
    }

    public function testAMissingBranchIsRefused(): void
    {
        $this->package('core', ['name' => 'acme/core']);
        $this->commit('First');

        $this->expectException(RepositoryFailure::class);
        $this->build('trunk');
    }

    /**
     * @return array<string, list<string>>
     */
    private function build(string $branch, string $output = 'out'): array
    {
        return new RepositoryBuilder($this->dir . '/source', self::URL, $branch)->build($this->dir . '/' . $output);
    }

    /**
     * @return array<mixed>
     */
    private function entry(string $name, string $version, string $output = 'out'): array
    {
        $data = json_decode((string) file_get_contents($this->dir . '/' . $output . '/packages.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $packages = $data['packages'] ?? null;
        self::assertIsArray($packages);
        $versions = $packages[$name] ?? null;
        self::assertIsArray($versions);
        $entry = $versions[$version] ?? null;
        self::assertIsArray($entry);

        return $entry;
    }

    /**
     * @param array<mixed> $entry
     * @return array{type: string, url: string, reference: string, shasum: string}
     */
    private function dist(array $entry): array
    {
        $dist = $entry['dist'] ?? null;
        self::assertIsArray($dist);
        $type = $dist['type'] ?? null;
        $url = $dist['url'] ?? null;
        $reference = $dist['reference'] ?? null;
        $shasum = $dist['shasum'] ?? null;
        self::assertIsString($type);
        self::assertIsString($url);
        self::assertIsString($reference);
        self::assertIsString($shasum);

        return ['type' => $type, 'url' => $url, 'reference' => $reference, 'shasum' => $shasum];
    }

    private function archive(string $url): string
    {
        return $this->dir . '/out/' . substr($url, strlen(self::URL));
    }

    /**
     * @return list<string>
     */
    private function entries(string $file): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && !str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }
        $zip->close();
        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function package(string $directory, array $manifest): void
    {
        $manifest['extra'] = ['branch-alias' => ['dev-main' => '0.1.x-dev']];
        $this->file('packages/' . $directory . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->file('packages/' . $directory . '/src/' . ucfirst($directory) . '.php', '<?php');
    }

    private function file(string $path, string $contents): void
    {
        $file = $this->dir . '/source/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), recursive: true);
        }
        file_put_contents($file, $contents);
    }

    private function commit(string $message): void
    {
        $this->git->run(['add', '--all']);
        $this->git->run(['commit', '--quiet', '--message=' . $message]);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $entries = scandir($path);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
