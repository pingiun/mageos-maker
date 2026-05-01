<?php

namespace App\Services;

/**
 * Pure: turns a Selection into a composer.json array.
 *
 * Uses the actual composer.json shipped by `mage-os/project-community-edition` for
 * the chosen version as the base — equivalent to running
 *   composer create-project --repository-url=https://repo.mage-os.org/ mage-os/project-community-edition .
 * Then layers `replace` entries for disabled sets/layers (subtractive) and adds
 * `require` entries for enabled add-on packages (additive).
 */
class Configurator
{
    public function __construct(
        private readonly Definitions $defs,
        private readonly CatalogRepository $catalog,
        private readonly string $repositoryUrl,
    ) {}

    public function build(Selection $selection): array
    {
        $resolved = $this->resolveProfileGroups($selection);

        $disabledSets = array_values(array_unique(array_merge($resolved['disableSets'], $selection->disabledSets)));
        $disabledLayers = array_values(array_unique(array_merge($resolved['disableLayers'], $selection->disabledLayers)));
        $effectiveAddons = array_values(array_unique(array_merge($resolved['enableAddons'], $selection->enabledAddons)));
        $effectiveEnabledLayers = array_values(array_unique(array_merge($resolved['enableLayers'], $selection->enabledLayers)));

        $composer = $this->baseComposer($selection->version);

        // Add-ons: append to require.
        foreach ($effectiveAddons as $addon) {
            foreach ($this->defs->addonPackages($addon) as $pkg) {
                $composer['require'][$pkg] = '*';
            }
        }
        // Non-stock layers that are enabled: append to require.
        foreach ($effectiveEnabledLayers as $layer) {
            if ($this->defs->isLayerStock($layer)) {
                continue;
            }
            foreach ($this->defs->layerPackages($layer) as $pkg) {
                $composer['require'][$pkg] = '*';
            }
        }
        ksort($composer['require']);

        // Disabled sets and disabled stock-layers: append to replace.
        $replace = $composer['replace'] ?? [];
        foreach ($disabledSets as $set) {
            foreach ($this->defs->setPackages($set) as $pkg) {
                $replace[$pkg] = '*';
            }
        }
        foreach ($disabledLayers as $layer) {
            if (! $this->defs->isLayerStock($layer)) {
                continue;
            }
            foreach ($this->defs->layerPackages($layer) as $pkg) {
                $replace[$pkg] = '*';
            }
        }
        if ($replace !== []) {
            ksort($replace);
            $composer['replace'] = $replace;
        }

        return $composer;
    }

    /**
     * Add-on names forced on by the currently-selected profile-group options.
     *
     * @return list<string>
     */
    public function forcedAddons(Selection $selection): array
    {
        return array_values(array_unique($this->resolveProfileGroups($selection)['enableAddons']));
    }

    /**
     * Layer names forced on by the currently-selected profile-group options
     * (only non-stock layers ever appear here — stock layers are on by default).
     *
     * @return list<string>
     */
    public function forcedLayers(Selection $selection): array
    {
        return array_values(array_unique($this->resolveProfileGroups($selection)['enableLayers']));
    }

    /**
     * Pull the stock composer.json from project-community-edition's package metadata.
     * Strips dist/source/version fields that don't belong in a project's composer.json.
     */
    private function baseComposer(string $version): array
    {
        $editionPackages = $this->catalog->packageVersions(config('mageos.edition_package'));
        $template = $editionPackages[$version] ?? null;

        if ($template === null) {
            return [
                'name' => config('mageos.edition_package'),
                'description' => 'Mage-OS project tailored with mageos-maker',
                'type' => 'project',
                'require' => [config('mageos.edition_package') => $version],
                'repositories' => [['type' => 'composer', 'url' => $this->repositoryUrl]],
                'minimum-stability' => 'stable',
                'prefer-stable' => true,
            ];
        }

        $skip = ['name', 'version', 'version_normalized', 'dist', 'source', 'time', 'uid', 'description'];
        $base = [];
        foreach ($template as $key => $value) {
            if (! in_array($key, $skip, true)) {
                $base[$key] = $value;
            }
        }

        $composer = [
            'name' => config('mageos.edition_package'),
            'description' => 'Mage-OS project tailored with mageos-maker',
        ] + $base + [
            'repositories' => [
                ['type' => 'composer', 'url' => $this->repositoryUrl],
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ];

        $composer['require'] ??= [];

        return $composer;
    }

    /**
     * @return array{enableAddons:list<string>, enableLayers:list<string>, disableSets:list<string>, disableLayers:list<string>}
     */
    private function resolveProfileGroups(Selection $selection): array
    {
        $enableAddons = $enableLayers = $disableSets = $disableLayers = [];

        foreach ($selection->profileGroups as $group => $optionName) {
            $option = $this->defs->profileGroupOption($group, $optionName);
            if ($option === null) {
                continue;
            }
            $enableAddons = array_merge($enableAddons, $option['enables']['addons'] ?? []);
            $enableLayers = array_merge($enableLayers, $option['enables']['layers'] ?? []);
            $disableSets = array_merge($disableSets, $option['disables']['sets'] ?? []);
            $disableLayers = array_merge($disableLayers, $option['disables']['layers'] ?? []);
        }

        return [
            'enableAddons' => $enableAddons,
            'enableLayers' => $enableLayers,
            'disableSets' => $disableSets,
            'disableLayers' => $disableLayers,
        ];
    }
}
