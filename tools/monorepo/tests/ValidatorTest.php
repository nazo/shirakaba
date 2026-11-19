<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Monorepo\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Tools\Monorepo\ComposerJson;
use Shirakaba\Tools\Monorepo\ValidationFailure;
use Shirakaba\Tools\Monorepo\Validator;

#[CoversClass(Validator::class)]
#[CoversClass(ComposerJson::class)]
final class ValidatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shirakaba-monorepo-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/packages', recursive: true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function testConsistentRepositoryHasNoErrors(): void
    {
        $this->writeRoot(self::root());
        $this->writePackage('core', self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']));
        $this->writePackage('http', self::package('http'));

        self::assertSame([], new Validator($this->dir)->validate());
    }

    public function testEveryPackageCarriesTheRootLicense(): void
    {
        $this->writeRoot(self::root());
        $this->writePackage('core', self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']));
        $this->writePackage('http', self::package('http'));
        file_put_contents($this->dir . '/LICENSE', 'MIT License');
        file_put_contents($this->dir . '/packages/core/LICENSE', 'MIT License');
        file_put_contents($this->dir . '/packages/http/LICENSE', 'Another text');

        self::assertSame(['packages/http/LICENSE must be a copy of the root LICENSE.'], new Validator($this->dir)->validate());
    }

    public function testDatabasePackagesCannotRequireTheFramework(): void
    {
        $root = self::root();
        $root['replace'] = [...$root['replace'], 'shirakaba/orm' => 'self.version', 'shirakaba/time' => 'self.version'];
        $root['autoload']['psr-4'] = [...$root['autoload']['psr-4'], 'Shirakaba\\Orm\\' => 'packages/orm/src/', 'Shirakaba\\Time\\' => 'packages/time/src/'];
        $root['autoload-dev']['psr-4'] = [
            ...$root['autoload-dev']['psr-4'],
            'Shirakaba\\Orm\\Tests\\' => 'packages/orm/tests/',
            'Shirakaba\\Time\\Tests\\' => 'packages/time/tests/',
        ];
        $this->writeRoot($root);
        $this->writePackage('core', self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']));
        $this->writePackage('http', self::package('http'));
        $this->writePackage('time', self::package('time'));
        $this->writePackage('orm', self::package('orm', require: ['shirakaba/time' => '^0.1', 'shirakaba/core' => '^0.1']));

        self::assertSame(
            [
                'packages/orm/composer.json: must be usable without the framework, so it cannot require "shirakaba/core"; move the integration to shirakaba/database-bridge (design/08-database.md).',
            ],
            new Validator($this->dir)->validate(),
        );
    }

    public function testRootDependencyWithoutConsumerIsReported(): void
    {
        $root = self::root();
        $root['require']['vendor/unused'] = '^1.0';
        $this->writeRoot($root);
        $this->writePackage('core', self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']));
        $this->writePackage('http', self::package('http'));

        self::assertSame(
            ['Root composer.json requires "vendor/unused", but no package needs it.'],
            new Validator($this->dir)->validate(),
        );
    }

    public function testPackageDependencyMissingFromRootIsReported(): void
    {
        $this->writeRoot(self::root());
        $this->writePackage('core', self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0', 'vendor/other' => '^2.0']));
        $this->writePackage('http', self::package('http'));

        self::assertSame(
            ['packages/core/composer.json: "vendor/other" must also be required (or required for development) by the root composer.json.'],
            new Validator($this->dir)->validate(),
        );
    }

    public function testMismatchedMetadataIsReported(): void
    {
        $this->writeRoot(self::root());
        $core = self::package('core', require: ['shirakaba/http' => '^0.2', 'shirakaba/missing' => '^0.1', 'psr/log' => '^2.0']);
        $core['name'] = 'shirakaba/kernel';
        $core['require']['php'] = '^8.4';
        $core['autoload']['psr-4'] = ['Shirakaba\\Kernel\\' => 'src/'];
        $this->writePackage('core', $core);
        $this->writePackage('http', self::package('http'));

        self::assertSame(
            [
                'packages/core/composer.json: "name" must be "shirakaba/core".',
                'packages/core/composer.json: "require.php" must be "^8.5".',
                'packages/core/composer.json: internal dependency "shirakaba/http" must use "^0.1".',
                'packages/core/composer.json: depends on unknown internal package "shirakaba/missing".',
                'packages/core/composer.json: "psr/log" constraint "^2.0" differs from the root "^3.0".',
                'Root autoload.psr-4 must map "Shirakaba\\Kernel\\" to "packages/core/src/".',
                'Root autoload.psr-4 maps "Shirakaba\\Core\\" to "packages/core/src/", but no package declares it.',
            ],
            new Validator($this->dir)->validate(),
        );
    }

    public function testReplaceMustListEveryPackage(): void
    {
        $root = self::root();
        unset($root['replace']['shirakaba/http']);
        $this->writeRoot($root);
        $this->writePackage('core', self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']));
        $this->writePackage('http', self::package('http'));

        self::assertSame(
            ['Root composer.json "replace" must be exactly {"shirakaba/core":"self.version","shirakaba/http":"self.version"}.'],
            new Validator($this->dir)->validate(),
        );
    }

    public function testPackageWithoutPhpConstraintIsReported(): void
    {
        $this->writeRoot(self::root());
        $core = self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']);
        unset($core['require']['php']);
        $this->writePackage('core', $core);
        $this->writePackage('http', self::package('http'));

        self::assertSame(
            ['packages/core/composer.json: "require.php" must be "^8.5".'],
            new Validator($this->dir)->validate(),
        );
    }

    public function testRootAutoloadForRemovedPackageIsReported(): void
    {
        $root = self::root();
        $root['autoload']['psr-4']['Shirakaba\\Gone\\'] = 'packages/gone/src/';
        $root['autoload-dev']['psr-4']['Shirakaba\\Tools\\'] = 'tools/src/';
        $this->writeRoot($root);
        $this->writePackage('core', self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']));
        $this->writePackage('http', self::package('http'));

        self::assertSame(
            ['Root autoload.psr-4 maps "Shirakaba\\Gone\\" to "packages/gone/src/", but no package declares it.'],
            new Validator($this->dir)->validate(),
        );
    }

    public function testPrefixDeclaredByTwoPackagesIsReported(): void
    {
        $this->writeRoot(self::root());
        $core = self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']);
        $core['autoload']['psr-4']['Shirakaba\\Http\\'] = 'src/';
        $this->writePackage('core', $core);
        $this->writePackage('http', self::package('http'));

        self::assertContains(
            'packages/http/composer.json: autoload.psr-4 prefix "Shirakaba\\Http\\" is already used by another package.',
            new Validator($this->dir)->validate(),
        );
    }

    public function testUnusedExtensionIsReported(): void
    {
        $root = self::root();
        $root['require']['ext-intl'] = '*';
        $this->writeRoot($root);
        $this->writePackage('core', self::package('core', require: ['shirakaba/http' => '^0.1', 'psr/log' => '^3.0']));
        $this->writePackage('http', self::package('http', require: ['ext-mbstring' => '*']));
        $root['require']['ext-mbstring'] = '*';
        $this->writeRoot($root);

        self::assertSame(
            ['Root composer.json requires "ext-intl", but no package needs it.'],
            new Validator($this->dir)->validate(),
        );
    }

    public function testMalformedValueFailsInsteadOfBeingIgnored(): void
    {
        $root = self::root();
        $root['require']['psr/log'] = ['^3.0'];
        $this->writeRoot($root);
        $this->writePackage('core', self::package('core'));

        $this->expectException(ValidationFailure::class);
        $this->expectExceptionMessage('"require.psr/log" must be a string');
        new Validator($this->dir)->validate();
    }

    public function testJsonArrayManifestIsRejected(): void
    {
        file_put_contents($this->dir . '/composer.json', '[]');

        $this->expectException(ValidationFailure::class);
        $this->expectExceptionMessage('must contain a JSON object');
        new Validator($this->dir)->validate();
    }

    public function testMissingPackageManifestFails(): void
    {
        $this->writeRoot(self::root());
        mkdir($this->dir . '/packages/empty');

        $this->expectException(ValidationFailure::class);
        new Validator($this->dir)->validate();
    }

    /**
     * @return array{license: string, require: array<string, string>, replace: array<string, string>, autoload: array{psr-4: array<string, string>}, autoload-dev: array{psr-4: array<string, string>}, extra: array{branch-alias: array{dev-main: string}}}
     */
    private static function root(): array
    {
        return [
            'license' => 'MIT',
            'require' => ['php' => '^8.5', 'psr/log' => '^3.0'],
            'replace' => ['shirakaba/core' => 'self.version', 'shirakaba/http' => 'self.version'],
            'autoload' => ['psr-4' => [
                'Shirakaba\\Core\\' => 'packages/core/src/',
                'Shirakaba\\Http\\' => 'packages/http/src/',
            ]],
            'autoload-dev' => ['psr-4' => [
                'Shirakaba\\Core\\Tests\\' => 'packages/core/tests/',
                'Shirakaba\\Http\\Tests\\' => 'packages/http/tests/',
            ]],
            'extra' => ['branch-alias' => ['dev-main' => '0.1.x-dev']],
        ];
    }

    /**
     * @param array<string, string> $require
     * @return array{name: string, license: string, require: array<string, string>, autoload: array{psr-4: array<string, string>}, autoload-dev: array{psr-4: array<string, string>}, extra: array{branch-alias: array{dev-main: string}}}
     */
    private static function package(string $directory, array $require = []): array
    {
        $namespace = 'Shirakaba\\' . ucfirst($directory) . '\\';

        return [
            'name' => 'shirakaba/' . $directory,
            'license' => 'MIT',
            'require' => ['php' => '^8.5', ...$require],
            'autoload' => ['psr-4' => [$namespace => 'src/']],
            'autoload-dev' => ['psr-4' => [$namespace . 'Tests\\' => 'tests/']],
            'extra' => ['branch-alias' => ['dev-main' => '0.1.x-dev']],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeRoot(array $data): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writePackage(string $directory, array $data): void
    {
        mkdir($this->dir . '/packages/' . $directory);
        file_put_contents($this->dir . '/packages/' . $directory . '/composer.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            $entries = scandir($path);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
