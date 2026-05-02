<?php

namespace Tests\Unit;

use App\Services\AddonVersionResolver;
use App\Services\CatalogRepository;
use App\Services\ComposerRepoIndex;
use App\Services\Configurator;
use App\Services\Definitions;
use App\Services\InstallTreeResolver;
use App\Services\Selection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubtoggleTest extends TestCase
{
    private string $graphsDir = 'graphs-subtoggle-test';

    protected function setUp(): void
    {
        parent::setUp();
        InstallTreeResolver::clearCache();
        Storage::disk('local')->deleteDirectory($this->graphsDir);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory($this->graphsDir);
        InstallTreeResolver::clearCache();
        parent::tearDown();
    }

    private function defs(): Definitions
    {
        return new Definitions(
            sets: [
                'two-factor-auth' => [
                    'name' => 'two-factor-auth',
                    'label' => '2FA',
                    'packages' => ['acme/module-2fa'],
                    'subtoggles' => [
                        ['name' => 'duo', 'label' => 'Duo', 'packages' => ['duosecurity/duo_api_php']],
                        ['name' => 'authy', 'label' => 'Authy', 'packages' => ['authy/php-sdk']],
                    ],
                ],
            ],
            layers: [], addons: [], profileGroups: [], profiles: [],
        );
    }

    public function test_disabled_subtoggle_only_replaces_its_own_packages(): void
    {
        $defs = $this->defs();
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('packageVersions')->willReturn([]);

        $cfg = new Configurator($defs, $catalog, new AddonVersionResolver($defs, new ComposerRepoIndex([], 'mageos-catalog'), 'mageos-catalog'), 'https://example.com/');
        $sel = new Selection('1.0.0', null, [], [], [], [], [], ['two-factor-auth.duo']);

        $composer = $cfg->build($sel);
        $this->assertArrayHasKey('duosecurity/duo_api_php', $composer['replace']);
        $this->assertArrayNotHasKey('authy/php-sdk', $composer['replace']);
        $this->assertArrayNotHasKey('acme/module-2fa', $composer['replace']);
    }

    public function test_disabled_parent_set_replaces_subtoggle_packages_too(): void
    {
        $defs = $this->defs();
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('packageVersions')->willReturn([]);

        $cfg = new Configurator($defs, $catalog, new AddonVersionResolver($defs, new ComposerRepoIndex([], 'mageos-catalog'), 'mageos-catalog'), 'https://example.com/');
        // Parent disabled; subtoggle list has no entries — but parent disable cascades.
        $sel = new Selection('1.0.0', null, ['two-factor-auth'], [], [], [], [], []);

        $composer = $cfg->build($sel);
        $this->assertArrayHasKey('acme/module-2fa', $composer['replace']);
        $this->assertArrayHasKey('duosecurity/duo_api_php', $composer['replace']);
        $this->assertArrayHasKey('authy/php-sdk', $composer['replace']);
    }

    public function test_install_tree_prunes_subtoggle_target(): void
    {
        Storage::disk('local')->put("$this->graphsDir/1.0.0/base.json", json_encode([
            'version' => '1.0.0',
            'rootRequires' => ['acme/root'],
            'packages' => [
                'acme/root' => ['version' => '1', 'type' => 'metapackage', 'requires' => ['acme/module-2fa'], 'replaces' => []],
                'acme/module-2fa' => ['version' => '1', 'type' => 'magento2-module', 'requires' => ['duosecurity/duo_api_php', 'authy/php-sdk'], 'replaces' => []],
                'duosecurity/duo_api_php' => ['version' => '1', 'type' => 'library', 'requires' => [], 'replaces' => []],
                'authy/php-sdk' => ['version' => '1', 'type' => 'library', 'requires' => [], 'replaces' => []],
            ],
        ]));

        $defs = $this->defs();
        $resolver = new InstallTreeResolver($defs, $this->graphsDir);

        $sel = new Selection('1.0.0', null, [], [], [], [], [], ['two-factor-auth.duo']);
        $tree = $resolver->resolve($sel);
        $names = array_column($tree['packages'], 'name');
        $this->assertContains('acme/module-2fa', $names);
        $this->assertNotContains('duosecurity/duo_api_php', $names);
        $this->assertContains('authy/php-sdk', $names);
    }
}
