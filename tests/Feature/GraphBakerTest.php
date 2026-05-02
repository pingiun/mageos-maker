<?php

namespace Tests\Feature;

use App\Services\CatalogRepository;
use App\Services\ComposerRepoIndex;
use App\Services\Definitions;
use App\Services\GraphBaker;
use App\Services\InstallTreeResolver;
use App\Services\Selection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bakes a real Mage-OS version using the cached include file already on disk
 * (storage/app/private/mageos-catalog/...). Mocks Packagist responses for the
 * handful of third-party packages so the test is deterministic and offline.
 */
class GraphBakerTest extends TestCase
{
    private string $graphsDir = 'graphs-baker-test';

    private string $packagistDir = 'packagist-cache-baker-test';

    protected function setUp(): void
    {
        parent::setUp();
        InstallTreeResolver::clearCache();
        Storage::disk('local')->deleteDirectory($this->graphsDir);
        Storage::disk('local')->deleteDirectory($this->packagistDir);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory($this->graphsDir);
        Storage::disk('local')->deleteDirectory($this->packagistDir);
        InstallTreeResolver::clearCache();
        parent::tearDown();
    }

    public function test_bakes_real_mageos_version_and_resolver_walks_it(): void
    {
        if (! Storage::disk('local')->exists('mageos-catalog/manifest.json')) {
            $this->markTestSkipped('Catalog cache not present; run php artisan mageos:catalog:update first.');
        }

        // Stub Packagist p2 with empty version lists for any third-party fetch.
        // The resolver records a warning and skips these — fine for the test, since
        // we only assert reachability over mage-os/* (which all comes from the cache).
        Http::fake([
            'repo.packagist.org/*' => Http::response(json_encode(['packages' => []]), 200),
        ]);

        $catalog = $this->app->make(CatalogRepository::class);
        $defs = new Definitions(
            sets: ['wishlist' => ['name' => 'wishlist', 'label' => 'W', 'packages' => ['mage-os/module-wishlist']]],
            layers: [], addons: [], profileGroups: [], profiles: [],
        );
        $baker = new GraphBaker($catalog, $defs, new ComposerRepoIndex([], 'mageos-catalog'), 'mage-os/project-community-edition', $this->graphsDir, $this->packagistDir);

        $result = $baker->bake('2.2.2');
        $this->assertTrue($result['baseWritten']);
        $this->assertFileExists(Storage::disk('local')->path("$this->graphsDir/2.2.2/base.json"));

        $graph = json_decode(Storage::disk('local')->get("$this->graphsDir/2.2.2/base.json"), true);
        $this->assertSame('2.2.2', $graph['version']);
        $this->assertNotEmpty($graph['packages']);
        $this->assertArrayHasKey('mage-os/product-community-edition', $graph['packages']);
        $this->assertArrayHasKey('mage-os/module-wishlist', $graph['packages']);
        // Wishlist's `replace` should preserve the legacy magento alias.
        $this->assertContains('magento/module-wishlist', $graph['packages']['mage-os/module-wishlist']['replaces']);
        // Platform requires must be stripped from package edges.
        foreach ($graph['packages'] as $name => $pkg) {
            foreach ($pkg['requires'] as $r) {
                $this->assertStringNotContainsString('ext-', $r, "platform require leaked into $name");
                $this->assertNotSame('php', $r, "php platform require leaked into $name");
            }
        }

        // Resolver walks the baked graph end-to-end.
        $resolver = new InstallTreeResolver($defs, $this->graphsDir);
        $sel = new Selection('2.2.2', null, [], [], [], [], []);
        $tree = $resolver->resolve($sel);
        $this->assertGreaterThan(200, $tree['count'], 'expected hundreds of mage-os packages reachable');
        $names = array_column($tree['packages'], 'name');
        $this->assertContains('mage-os/module-wishlist', $names);

        // Disabling the wishlist set drops it from the tree.
        $selDisabled = new Selection('2.2.2', null, ['wishlist'], [], [], [], []);
        $treeDisabled = $resolver->resolve($selDisabled);
        $this->assertNotContains('mage-os/module-wishlist', array_column($treeDisabled['packages'], 'name'));
        $this->assertLessThan($tree['count'], $treeDisabled['count']);
    }

    public function test_rebake_is_idempotent_when_input_unchanged(): void
    {
        if (! Storage::disk('local')->exists('mageos-catalog/manifest.json')) {
            $this->markTestSkipped('Catalog cache not present.');
        }
        Http::fake(['repo.packagist.org/*' => Http::response(json_encode(['packages' => []]), 200)]);

        $catalog = $this->app->make(CatalogRepository::class);
        $defs = new Definitions([], [], [], [], []);
        $baker = new GraphBaker($catalog, $defs, new ComposerRepoIndex([], 'mageos-catalog'), 'mage-os/project-community-edition', $this->graphsDir, $this->packagistDir);

        $first = $baker->bake('2.2.2');
        $this->assertTrue($first['baseWritten']);
        $second = $baker->bake('2.2.2');
        $this->assertFalse($second['baseWritten'], 'second bake should be no-op when content matches');
    }
}
