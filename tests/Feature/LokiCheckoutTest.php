<?php

namespace Tests\Feature;

use App\Services\Configurator;
use App\Services\Selection;
use Tests\TestCase;

/**
 * End-to-end Loki Checkout: real definitions YAML → composer.json shape,
 * via the live service-container-resolved Configurator. The single
 * loki-checkout option exposes Hyvä + Luma variants; this locks in
 * the variant resolution and theme gating.
 */
class LokiCheckoutTest extends TestCase
{
    public function test_hyva_theme_picks_hyva_variant_by_default(): void
    {
        $cfg = $this->app->make(Configurator::class);
        $sel = new Selection('2.2.2', null, [], [], [], ['loki-checkout-hyva'],
            ['theme' => 'hyva', 'checkout' => 'loki-checkout'], [], [], []);
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('loki-checkout/magento2-hyva', $composer['require']);
        $this->assertContains(
            ['type' => 'composer', 'url' => 'https://composer.yireo.com/'],
            $composer['repositories'],
        );
    }

    public function test_luma_theme_falls_back_to_luma_variant_with_lean_subtoggle(): void
    {
        $cfg = $this->app->make(Configurator::class);
        // Luma theme → hyva variant's requires fail → auto-fall back to luma variant.
        $sel = new Selection(
            '2.2.2', null, [], [], [], ['loki-checkout-luma', 'loki-luma-components'],
            ['theme' => 'luma', 'checkout' => 'loki-checkout'], [],
            ['checkout.loki-checkout.luma.luma-components'], []
        );
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('loki-checkout/magento2-luma', $composer['require']);
        $this->assertArrayHasKey('loki-theme/magento2-luma-components', $composer['require']);
    }

    public function test_luma_variant_lean_off(): void
    {
        $cfg = $this->app->make(Configurator::class);
        $sel = new Selection(
            '2.2.2', null, [], [], [], ['loki-checkout-luma'],
            ['theme' => 'luma', 'checkout' => 'loki-checkout'], [], [], []
        );
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('loki-checkout/magento2-luma', $composer['require']);
        $this->assertArrayNotHasKey('loki-theme/magento2-luma-components', $composer['require']);
    }

    public function test_user_can_override_to_luma_variant_on_hyva_theme(): void
    {
        $cfg = $this->app->make(Configurator::class);
        // User explicitly picks luma variant on hyva theme — preferAlternative is
        // a soft hint, not a block, so it stands.
        $sel = new Selection(
            '2.2.2', null, [], [], [], ['loki-checkout-luma'],
            ['theme' => 'hyva', 'checkout' => 'loki-checkout'], [], [],
            ['checkout.loki-checkout' => 'luma']
        );
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('loki-checkout/magento2-luma', $composer['require']);
        $this->assertArrayNotHasKey('loki-checkout/magento2-hyva', $composer['require']);
    }
}
