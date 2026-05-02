<?php

namespace Tests\Unit;

use App\Services\CatalogRepository;
use App\Services\Configurator;
use App\Services\Definitions;
use App\Services\Selection;
use Tests\TestCase;

class OptionSubtoggleTest extends TestCase
{
    private function defs(): Definitions
    {
        return new Definitions(
            sets: [], layers: [],
            addons: [
                'loki-luma' => ['name' => 'loki-luma', 'label' => 'Loki', 'packages' => ['loki/magento2-luma']],
                'lean-js' => ['name' => 'lean-js', 'label' => 'Lean', 'packages' => ['loki/luma-components']],
            ],
            profileGroups: [
                'theme' => ['name' => 'theme', 'label' => 'Theme', 'options' => [
                    ['name' => 'luma', 'label' => 'Luma', 'default' => true],
                ]],
                'checkout' => ['name' => 'checkout', 'label' => 'Checkout', 'options' => [
                    ['name' => 'default', 'label' => 'Default', 'default' => true],
                    [
                        'name' => 'loki',
                        'label' => 'Loki',
                        'enables' => ['addons' => ['loki-luma']],
                        'subtoggles' => [
                            ['name' => 'lean', 'label' => 'Lean', 'addons' => ['lean-js'], 'default' => true],
                        ],
                    ],
                ]],
            ],
            profiles: [],
        );
    }

    public function test_subtoggle_pulls_addon_only_when_parent_picked_and_enabled(): void
    {
        $defs = $this->defs();
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('packageVersions')->willReturn([]);
        $cfg = new Configurator($defs, $catalog, new \App\Services\AddonVersionResolver($defs, 'mageos-catalog', null, null), 'https://example.com/');

        // parent picked + subtoggle on (the default) → both addons in require
        $sel = new Selection('1.0.0', null, [], [], [], ['loki-luma'],
            ['theme' => 'luma', 'checkout' => 'loki'], [],
            ['checkout.loki.lean']);
        $composer = $cfg->build($sel);
        $this->assertArrayHasKey('loki/magento2-luma', $composer['require']);
        $this->assertArrayHasKey('loki/luma-components', $composer['require']);

        // parent picked + subtoggle off → only the parent addon
        $sel = new Selection('1.0.0', null, [], [], [], ['loki-luma'],
            ['theme' => 'luma', 'checkout' => 'loki'], [],
            []);
        $composer = $cfg->build($sel);
        $this->assertArrayHasKey('loki/magento2-luma', $composer['require']);
        $this->assertArrayNotHasKey('loki/luma-components', $composer['require']);

        // parent NOT picked but subtoggle key persists in selection → ignored
        $sel = new Selection('1.0.0', null, [], [], [], [],
            ['theme' => 'luma', 'checkout' => 'default'], [],
            ['checkout.loki.lean']);
        $composer = $cfg->build($sel);
        $this->assertArrayNotHasKey('loki/magento2-luma', $composer['require'] ?? []);
        $this->assertArrayNotHasKey('loki/luma-components', $composer['require'] ?? []);
    }

    public function test_default_on_subtoggles_seeded_into_default_selection(): void
    {
        $sel = Selection::default('1.0.0', $this->defs());
        $this->assertContains('checkout.loki.lean', $sel->enabledOptionSubtoggles);
    }
}
