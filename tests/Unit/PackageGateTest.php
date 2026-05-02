<?php

namespace Tests\Unit;

use App\Services\AddonVersionResolver;
use App\Services\CatalogRepository;
use App\Services\ComposerRepoIndex;
use App\Services\Configurator;
use App\Services\Definitions;
use App\Services\Selection;
use Tests\TestCase;

/**
 * Per-package `requires:` gates: a package entry only contributes when the
 * named addon/layer/set/package is present in the build context. See
 * Definitions::normalizePackages() for the schema.
 */
class PackageGateTest extends TestCase
{
    private function configurator(Definitions $defs): Configurator
    {
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('packageVersions')->willReturn([]);

        return new Configurator(
            $defs,
            $catalog,
            new AddonVersionResolver($defs, new ComposerRepoIndex([], 'mageos-catalog'), 'mageos-catalog'),
            'https://example.com/',
        );
    }

    public function test_layer_package_gated_on_absent_addon_is_skipped(): void
    {
        $defs = new Definitions(
            sets: [],
            layers: [
                'hyva-compat' => [
                    'name' => 'hyva-compat',
                    'label' => 'Compat',
                    'stock' => false,
                    'packages' => [
                        ['name' => 'hyva-themes/magento2-amasty-shopby-compat', 'requires' => ['addon' => 'amasty-shopby']],
                        'hyva-themes/magento2-always-included',
                    ],
                ],
            ],
            addons: [],
            profileGroups: [],
            profiles: [],
        );

        $cfg = $this->configurator($defs);
        $sel = new Selection('1.0.0', null, [], [], ['hyva-compat'], [], [], [], []);
        $composer = $cfg->build($sel);

        $this->assertArrayNotHasKey('hyva-themes/magento2-amasty-shopby-compat', $composer['require']);
        $this->assertArrayHasKey('hyva-themes/magento2-always-included', $composer['require']);
    }

    public function test_layer_package_gated_on_present_addon_is_included(): void
    {
        $defs = new Definitions(
            sets: [],
            layers: [
                'hyva-compat' => [
                    'name' => 'hyva-compat',
                    'label' => 'Compat',
                    'stock' => false,
                    'packages' => [
                        ['name' => 'hyva-themes/magento2-amasty-shopby-compat', 'requires' => ['addon' => 'amasty-shopby']],
                    ],
                ],
            ],
            addons: [
                'amasty-shopby' => ['name' => 'amasty-shopby', 'label' => 'Amasty Shopby', 'packages' => ['amasty/shopby']],
            ],
            profileGroups: [],
            profiles: [],
        );

        $cfg = $this->configurator($defs);
        $sel = new Selection('1.0.0', null, [], [], ['hyva-compat'], ['amasty-shopby'], [], [], []);
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('hyva-themes/magento2-amasty-shopby-compat', $composer['require']);
    }

    public function test_replace_entry_gated_on_package_skips_when_root_already_replaced(): void
    {
        // graphql layer replaces a graph-ql package only if its base module is
        // still in `require` — i.e. the base set wasn't disabled away.
        $defs = new Definitions(
            sets: [
                'paypal' => [
                    'name' => 'paypal',
                    'label' => 'PayPal',
                    'packages' => ['mage-os/module-paypal'],
                ],
            ],
            layers: [
                'graphql' => [
                    'name' => 'graphql',
                    'label' => 'GraphQL',
                    'packages' => [
                        ['name' => 'mage-os/module-paypal-graph-ql', 'requires' => ['package' => 'mage-os/module-paypal']],
                        'mage-os/module-graph-ql',
                    ],
                ],
            ],
            addons: [],
            profileGroups: [],
            profiles: [],
        );

        $cfg = $this->configurator($defs);
        // Disable both paypal (base) and graphql (replace target) — the
        // gated graph-ql entry should *not* end up in replace because its
        // base module isn't in require any more.
        $sel = new Selection('1.0.0', null, ['paypal'], ['graphql'], [], [], [], [], []);
        $composer = $cfg->build($sel);

        $this->assertArrayHasKey('mage-os/module-paypal', $composer['replace']);
        $this->assertArrayHasKey('mage-os/module-graph-ql', $composer['replace']);
        $this->assertArrayNotHasKey('mage-os/module-paypal-graph-ql', $composer['replace']);
    }
}
