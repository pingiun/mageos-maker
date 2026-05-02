<?php

namespace Tests\Unit;

use App\Services\CatalogRepository;
use App\Services\Configurator;
use App\Services\Definitions;
use App\Services\Selection;
use Tests\TestCase;

class OptionVariantTest extends TestCase
{
    private function defs(): Definitions
    {
        return new Definitions(
            sets: [], layers: [],
            addons: [
                'loki-hyva' => ['name' => 'loki-hyva', 'label' => 'Hyva', 'packages' => ['loki/hyva']],
                'loki-luma' => ['name' => 'loki-luma', 'label' => 'Luma', 'packages' => ['loki/luma']],
                'loki-lean' => ['name' => 'loki-lean', 'label' => 'Lean', 'packages' => ['loki/lean']],
            ],
            profileGroups: [
                'theme' => ['name' => 'theme', 'label' => 'Theme', 'options' => [
                    ['name' => 'luma', 'label' => 'Luma', 'default' => true],
                    ['name' => 'hyva', 'label' => 'Hyva'],
                ]],
                'checkout' => ['name' => 'checkout', 'label' => 'Checkout', 'options' => [
                    ['name' => 'default', 'label' => 'Default', 'default' => true],
                    ['name' => 'loki', 'label' => 'Loki', 'variants' => [
                        [
                            'name' => 'hyva', 'label' => 'Hyva variant', 'default' => true,
                            'requires' => ['profileGroups' => ['theme' => 'hyva']],
                            'enables' => ['addons' => ['loki-hyva']],
                        ],
                        [
                            'name' => 'luma', 'label' => 'Luma variant',
                            'enables' => ['addons' => ['loki-luma']],
                            'subtoggles' => [
                                ['name' => 'lean', 'label' => 'Lean', 'addons' => ['loki-lean'], 'default' => true],
                            ],
                        ],
                    ]],
                ]],
            ],
            profiles: [],
        );
    }

    public function test_active_variant_resolution(): void
    {
        $defs = $this->defs();
        $this->assertSame('hyva', $defs->optionActiveVariant('checkout', 'loki', ['theme' => 'hyva'], []));
        $this->assertSame('luma', $defs->optionActiveVariant('checkout', 'loki', ['theme' => 'luma'], []));
        $this->assertSame('luma', $defs->optionActiveVariant('checkout', 'loki', ['theme' => 'luma'], ['checkout.loki' => 'hyva']));
        $this->assertSame('luma', $defs->optionActiveVariant('checkout', 'loki', ['theme' => 'hyva'], ['checkout.loki' => 'luma']));
    }

    public function test_active_variant_addons_flow_into_require(): void
    {
        $defs = $this->defs();
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('packageVersions')->willReturn([]);
        $cfg = new Configurator($defs, $catalog, 'https://example.com/');

        $sel = new Selection('1.0.0', null, [], [], [], ['loki-hyva'],
            ['theme' => 'hyva', 'checkout' => 'loki'], [], [], []);
        $c = $cfg->build($sel);
        $this->assertArrayHasKey('loki/hyva', $c['require']);
        $this->assertArrayNotHasKey('loki/luma', $c['require']);
    }

    public function test_variant_subtoggle_uses_4_segment_key(): void
    {
        $defs = $this->defs();
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('packageVersions')->willReturn([]);
        $cfg = new Configurator($defs, $catalog, 'https://example.com/');

        $sel = new Selection('1.0.0', null, [], [], [], ['loki-luma', 'loki-lean'],
            ['theme' => 'luma', 'checkout' => 'loki'], [],
            ['checkout.loki.luma.lean'], []);
        $c = $cfg->build($sel);
        $this->assertArrayHasKey('loki/luma', $c['require']);
        $this->assertArrayHasKey('loki/lean', $c['require']);
    }

    public function test_default_on_variant_subtoggles_seeded(): void
    {
        $sel = Selection::default('1.0.0', $this->defs());
        $this->assertContains('checkout.loki.luma.lean', $sel->enabledOptionSubtoggles);
    }
}
