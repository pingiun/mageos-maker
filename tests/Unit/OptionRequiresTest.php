<?php

namespace Tests\Unit;

use App\Services\CatalogRepository;
use App\Services\Configurator;
use App\Services\Definitions;
use App\Services\Selection;
use Tests\TestCase;

class OptionRequiresTest extends TestCase
{
    private function defs(): Definitions
    {
        return new Definitions(
            sets: [], layers: [],
            addons: [
                'loki-luma' => ['name' => 'loki-luma', 'label' => 'Loki Luma', 'packages' => ['loki/luma']],
                'loki-hyva' => ['name' => 'loki-hyva', 'label' => 'Loki Hyvä', 'packages' => ['loki/hyva']],
            ],
            profileGroups: [
                'theme' => ['name' => 'theme', 'label' => 'Theme', 'options' => [
                    ['name' => 'luma', 'label' => 'Luma', 'default' => true],
                    ['name' => 'hyva', 'label' => 'Hyva'],
                ]],
                'checkout' => ['name' => 'checkout', 'label' => 'Checkout', 'options' => [
                    ['name' => 'default', 'label' => 'Default', 'default' => true],
                    ['name' => 'loki-luma', 'label' => 'Loki Luma',
                     'requires' => ['profileGroups' => ['theme' => 'luma']],
                     'enables' => ['addons' => ['loki-luma']]],
                    ['name' => 'loki-hyva', 'label' => 'Loki Hyva',
                     'requires' => ['profileGroups' => ['theme' => 'hyva']],
                     'enables' => ['addons' => ['loki-hyva']]],
                ]],
            ],
            profiles: [],
        );
    }

    public function test_definitions_helper_reports_availability(): void
    {
        $defs = $this->defs();
        $this->assertTrue($defs->optionMeetsRequires('checkout', 'default', ['theme' => 'luma']));
        $this->assertTrue($defs->optionMeetsRequires('checkout', 'loki-luma', ['theme' => 'luma']));
        $this->assertFalse($defs->optionMeetsRequires('checkout', 'loki-luma', ['theme' => 'hyva']));
        $this->assertTrue($defs->optionMeetsRequires('checkout', 'loki-hyva', ['theme' => 'hyva']));
    }

    public function test_configurator_includes_addons_when_requires_satisfied(): void
    {
        $defs = $this->defs();
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('packageVersions')->willReturn([]);
        $cfg = new Configurator($defs, $catalog, 'https://example.com/');

        $sel = new Selection('1.0.0', null, [], [], [], ['loki-hyva'],
            ['theme' => 'hyva', 'checkout' => 'loki-hyva'], [], []);
        $composer = $cfg->build($sel);
        $this->assertArrayHasKey('loki/hyva', $composer['require']);
    }

    public function test_configurator_falls_back_to_default_when_requires_unmet(): void
    {
        $defs = $this->defs();
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('packageVersions')->willReturn([]);
        $cfg = new Configurator($defs, $catalog, 'https://example.com/');

        // theme=luma but checkout claims loki-hyva — invalid combo. Configurator
        // treats checkout as if it were the group's default (no addons).
        $sel = new Selection('1.0.0', null, [], [], [], [],
            ['theme' => 'luma', 'checkout' => 'loki-hyva'], [], []);
        $composer = $cfg->build($sel);
        $this->assertArrayNotHasKey('loki/hyva', $composer['require'] ?? []);
        $this->assertArrayNotHasKey('loki/luma', $composer['require'] ?? []);
    }
}
