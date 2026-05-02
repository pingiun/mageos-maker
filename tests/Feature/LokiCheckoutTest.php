<?php

namespace Tests\Feature;

use App\Services\Configurator;
use App\Services\Selection;
use Tests\TestCase;

/**
 * End-to-end Loki Checkout: real definitions YAML → composer.json shape,
 * via the live service-container-resolved Configurator. Locks in the
 * theme-gating + option-subtoggle wiring on top of the seeded definitions.
 */
class LokiCheckoutTest extends TestCase
{
    public function test_hyva_theme_with_loki_hyva_pulls_in_loki_hyva_package(): void
    {
        $cfg = $this->app->make(Configurator::class);
        $sel = new Selection('2.2.2', null, [], [], [], ['loki-checkout-hyva'],
            ['theme' => 'hyva', 'checkout' => 'loki-checkout-hyva'], [], []);
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('loki-checkout/magento2-hyva', $composer['require']);
        $this->assertContains(
            ['type' => 'composer', 'url' => 'https://composer.yireo.com/'],
            $composer['repositories'],
        );
    }

    public function test_luma_theme_with_loki_luma_and_lean_subtoggle(): void
    {
        $cfg = $this->app->make(Configurator::class);
        $sel = new Selection('2.2.2', null, [], [], [], ['loki-checkout-luma', 'loki-luma-components'],
            ['theme' => 'luma', 'checkout' => 'loki-checkout-luma'], [],
            ['checkout.loki-checkout-luma.luma-components']);
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('loki-checkout/magento2-luma', $composer['require']);
        $this->assertArrayHasKey('loki-theme/magento2-luma-components', $composer['require']);
    }

    public function test_luma_theme_with_loki_luma_lean_off(): void
    {
        $cfg = $this->app->make(Configurator::class);
        $sel = new Selection('2.2.2', null, [], [], [], ['loki-checkout-luma'],
            ['theme' => 'luma', 'checkout' => 'loki-checkout-luma'], [], []);
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('loki-checkout/magento2-luma', $composer['require']);
        $this->assertArrayNotHasKey('loki-theme/magento2-luma-components', $composer['require']);
    }

    public function test_loki_hyva_with_luma_theme_is_a_hard_invalid_combination_and_falls_back(): void
    {
        $cfg = $this->app->make(Configurator::class);
        $sel = new Selection('2.2.2', null, [], [], [], [],
            ['theme' => 'luma', 'checkout' => 'loki-checkout-hyva'], [], []);
        $composer = $cfg->build($sel);

        $this->assertArrayNotHasKey('loki-checkout/magento2-hyva', $composer['require'] ?? []);
        $this->assertArrayNotHasKey('loki-checkout/magento2-luma', $composer['require'] ?? []);
    }

    public function test_loki_luma_with_hyva_theme_is_allowed_just_not_recommended(): void
    {
        $cfg = $this->app->make(Configurator::class);
        $sel = new Selection('2.2.2', null, [], [], [], ['loki-checkout-luma'],
            ['theme' => 'hyva', 'checkout' => 'loki-checkout-luma'], [], []);
        $composer = $cfg->build($sel);

        // recommends — not a hard requires — so the addon still goes in.
        $this->assertArrayHasKey('loki-checkout/magento2-luma', $composer['require']);
    }
}
