<?php

namespace App\Services;

/**
 * Pure: turns a Selection into a composer.json array.
 *
 * Uses the actual composer.json shipped by `mage-os/project-community-edition` for
 * the chosen version as the base — equivalent to running
 *   composer create-project --repository-url=https://repo.mage-os.org/ mage-os/project-community-edition .
 * Then layers `replace` entries for disabled sets/layers and adds `require` entries
 * for explicitly-enabled add-on packages (e.g. Hyvä).
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
        // 1. Resolve profile-group choices → effective enabled/disabled sets and layers.
        [$enableSets, $disableSets, $enableLayers, $disableLayers] = $this->resolveProfileGroups($selection);

        $enabledSets = array_values(array_unique(array_merge($enableSets, $selection->enabledSets)));
        $disabledSets = array_values(array_diff(array_unique(array_merge($disableSets, $selection->disabledSets)), $enabledSets));
        $enabledLayers = array_values(array_unique(array_merge($enableLayers, $selection->enabledLayers)));
        $disabledLayers = array_values(array_diff(array_unique(array_merge($disableLayers, $selection->disabledLayers)), $enabledLayers));

        // 2. Start from the upstream project-community-edition composer.json template.
        $composer = $this->baseComposer($selection->version);

        // 3. Append explicitly-enabled add-on packages to require.
        foreach ($enabledSets as $set) {
            foreach ($this->defs->setPackages($set) as $pkg) {
                $composer['require'][$pkg] = '*';
            }
        }
        foreach ($enabledLayers as $layer) {
            foreach ($this->defs->layerPackages($layer) as $pkg) {
                $composer['require'][$pkg] = '*';
            }
        }
        ksort($composer['require']);

        // 4. Build replace from disabled sets & layers.
        $replace = $composer['replace'] ?? [];
        foreach ($disabledSets as $set) {
            foreach ($this->defs->setPackages($set) as $pkg) {
                $replace[$pkg] = '*';
            }
        }
        foreach ($disabledLayers as $layer) {
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
     * Pull the stock composer.json from project-community-edition's package metadata.
     * Strips dist/source/version fields that don't belong in a project's composer.json.
     */
    private function baseComposer(string $version): array
    {
        $editionPackages = $this->catalog->packageVersions(config('mageos.edition_package'));
        $template = $editionPackages[$version] ?? null;

        if ($template === null) {
            // Catalog cold — emit a minimal fallback that still requires the meta-package.
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

        // Drop fields that only make sense on the upstream package itself.
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
     * @return array{0:list<string>,1:list<string>,2:list<string>,3:list<string>}
     */
    private function resolveProfileGroups(Selection $selection): array
    {
        $enableSets = $disableSets = $enableLayers = $disableLayers = [];

        foreach ($selection->profileGroups as $group => $optionName) {
            $option = $this->defs->profileGroupOption($group, $optionName);
            if ($option === null) {
                continue;
            }
            $enableSets = array_merge($enableSets, $option['enables']['sets'] ?? []);
            $enableLayers = array_merge($enableLayers, $option['enables']['layers'] ?? []);
            $disableSets = array_merge($disableSets, $option['disables']['sets'] ?? []);
            $disableLayers = array_merge($disableLayers, $option['disables']['layers'] ?? []);
        }

        return [$enableSets, $disableSets, $enableLayers, $disableLayers];
    }
}
