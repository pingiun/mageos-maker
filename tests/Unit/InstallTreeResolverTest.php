<?php

namespace Tests\Unit;

use App\Services\Definitions;
use App\Services\InstallTreeResolver;
use App\Services\Selection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstallTreeResolverTest extends TestCase
{
    private string $graphsDir = 'graphs-test';

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

    public function test_bfs_walks_reachable_set(): void
    {
        $this->writeBase('1.0.0', [
            'rootRequires' => ['acme/root'],
            'packages' => [
                'acme/root' => ['version' => '1.0.0', 'type' => 'metapackage', 'requires' => ['acme/a', 'acme/b'], 'replaces' => []],
                'acme/a' => ['version' => '1.0.0', 'type' => 'magento2-module', 'requires' => ['acme/c'], 'replaces' => []],
                'acme/b' => ['version' => '1.0.0', 'type' => 'magento2-module', 'requires' => [], 'replaces' => []],
                'acme/c' => ['version' => '1.0.0', 'type' => 'library', 'requires' => [], 'replaces' => []],
            ],
        ]);

        $defs = $this->emptyDefinitions();
        $resolver = new InstallTreeResolver($defs, $this->graphsDir);
        $sel = new Selection('1.0.0', null, [], [], [], [], []);

        $result = $resolver->resolve($sel);

        $this->assertSame(4, $result['count']);
        $this->assertEqualsCanonicalizing(
            ['acme/root', 'acme/a', 'acme/b', 'acme/c'],
            array_column($result['packages'], 'name'),
        );
        $this->assertSame(['library' => 1, 'magento2-module' => 2, 'metapackage' => 1], $result['byType']);
        $this->assertFalse($result['missing']);
    }

    public function test_disabled_set_prunes_subtree(): void
    {
        $this->writeBase('1.0.0', [
            'rootRequires' => ['acme/root'],
            'packages' => [
                'acme/root' => ['version' => '1.0.0', 'type' => 'metapackage', 'requires' => ['acme/wishlist'], 'replaces' => []],
                'acme/wishlist' => ['version' => '1.0.0', 'type' => 'magento2-module', 'requires' => ['acme/wishlist-graphql'], 'replaces' => []],
                'acme/wishlist-graphql' => ['version' => '1.0.0', 'type' => 'magento2-module', 'requires' => [], 'replaces' => []],
            ],
        ]);

        $defs = new Definitions(
            sets: ['wishlist' => ['name' => 'wishlist', 'label' => 'W', 'packages' => ['acme/wishlist']]],
            layers: [], addons: [], profileGroups: [], profiles: [],
        );

        $resolver = new InstallTreeResolver($defs, $this->graphsDir);
        $sel = new Selection('1.0.0', null, ['wishlist'], [], [], [], []);
        $result = $resolver->resolve($sel);

        $names = array_column($result['packages'], 'name');
        $this->assertContains('acme/root', $names);
        $this->assertNotContains('acme/wishlist', $names);
        // wishlist-graphql is only reachable via wishlist; pruning wishlist orphans it.
        $this->assertNotContains('acme/wishlist-graphql', $names);
        $this->assertContains('acme/wishlist', $result['disabledHits']);
    }

    public function test_delta_for_non_default_option_extends_graph(): void
    {
        $this->writeBase('1.0.0', [
            'rootRequires' => ['acme/root'],
            'packages' => [
                'acme/root' => ['version' => '1.0.0', 'type' => 'metapackage', 'requires' => [], 'replaces' => []],
            ],
        ]);
        $this->writeDelta('1.0.0', 'theme', 'hyva', [
            'addRequires' => ['hyva/theme'],
            'addPackages' => [
                'hyva/theme' => ['version' => '1.0.0', 'type' => 'magento2-theme', 'requires' => ['hyva/lib'], 'replaces' => []],
                'hyva/lib' => ['version' => '1.0.0', 'type' => 'library', 'requires' => [], 'replaces' => []],
            ],
        ]);

        $defs = new Definitions(
            sets: [], layers: [], addons: [],
            profileGroups: ['theme' => ['name' => 'theme', 'label' => 'Theme', 'options' => [
                ['name' => 'luma', 'label' => 'Luma', 'default' => true],
                ['name' => 'hyva', 'label' => 'Hyva'],
            ]]],
            profiles: [],
        );
        $resolver = new InstallTreeResolver($defs, $this->graphsDir);
        $sel = new Selection('1.0.0', null, [], [], [], [], ['theme' => 'hyva']);
        $result = $resolver->resolve($sel);
        $names = array_column($result['packages'], 'name');
        $this->assertEqualsCanonicalizing(['acme/root', 'hyva/theme', 'hyva/lib'], $names);
    }

    public function test_missing_graph_falls_back_to_latest(): void
    {
        $this->writeBase('1.0.0', [
            'rootRequires' => ['acme/root'],
            'packages' => ['acme/root' => ['version' => '1.0.0', 'type' => 'metapackage', 'requires' => [], 'replaces' => []]],
        ]);
        $resolver = new InstallTreeResolver($this->emptyDefinitions(), $this->graphsDir);
        $sel = new Selection('9.9.9', null, [], [], [], [], []);
        $r = $resolver->resolve($sel);
        $this->assertTrue($r['missing']);
        $this->assertSame('1.0.0', $r['fallbackVersion']);
        $this->assertSame(1, $r['count']);
    }

    public function test_handles_cycles(): void
    {
        $this->writeBase('1.0.0', [
            'rootRequires' => ['a/x'],
            'packages' => [
                'a/x' => ['version' => '1', 'type' => 'library', 'requires' => ['a/y'], 'replaces' => []],
                'a/y' => ['version' => '1', 'type' => 'library', 'requires' => ['a/x'], 'replaces' => []],
            ],
        ]);
        $resolver = new InstallTreeResolver($this->emptyDefinitions(), $this->graphsDir);
        $r = $resolver->resolve(new Selection('1.0.0', null, [], [], [], [], []));
        $this->assertSame(2, $r['count']);
    }

    public function test_performance_under_5ms(): void
    {
        // Synthesize a 500-node graph (chain + fan-out) and time 100 resolves.
        $packages = [];
        $rootRequires = [];
        for ($i = 0; $i < 500; $i++) {
            $deps = [];
            if ($i + 1 < 500) {
                $deps[] = "acme/p$i-next";
            }
            // tiny fan-out to non-existent leaves to grow the edge count realistically
            for ($j = 0; $j < 4 && $i + $j + 1 < 500; $j++) {
                $deps[] = 'acme/p'.($i + $j + 1);
            }
            $packages["acme/p$i"] = ['version' => '1.0.0', 'type' => 'library', 'requires' => $deps, 'replaces' => []];
        }
        $rootRequires[] = 'acme/p0';
        $this->writeBase('perf', ['rootRequires' => $rootRequires, 'packages' => $packages]);

        $resolver = new InstallTreeResolver($this->emptyDefinitions(), $this->graphsDir);
        $sel = new Selection('perf', null, [], [], [], [], []);

        // Warm cache.
        $resolver->resolve($sel);
        InstallTreeResolver::clearCache();

        $start = hrtime(true);
        for ($i = 0; $i < 50; $i++) {
            $resolver->resolve($sel);
        }
        $perIterMicros = ((hrtime(true) - $start) / 50) / 1000;

        $this->assertLessThan(5000, $perIterMicros, "resolve() took $perIterMicros µs/iter, budget is 5000 µs");
    }

    private function emptyDefinitions(): Definitions
    {
        return new Definitions([], [], [], [], []);
    }

    private function writeBase(string $version, array $partial): void
    {
        $graph = ['version' => $version] + $partial;
        Storage::disk('local')->put("$this->graphsDir/$version/base.json", json_encode($graph));
    }

    private function writeDelta(string $version, string $group, string $option, array $partial): void
    {
        $delta = ['version' => $version, 'appliesTo' => ['group' => $group, 'option' => $option]] + $partial;
        Storage::disk('local')->put("$this->graphsDir/$version/options/$group/$option.json", json_encode($delta));
    }
}
