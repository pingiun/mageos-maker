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

    public function build(Selection $selection, string $hyvaProject = ''): array
    {
        $resolved = $this->resolveProfileGroups($selection);

        $disabledSets = array_values(array_unique(array_merge($resolved['disableSets'], $selection->disabledSets)));
        $disabledLayers = array_values(array_unique(array_merge($resolved['disableLayers'], $selection->disabledLayers)));
        // Forced items always go in, soft defaults only when echoed back via the form.
        $effectiveAddons = array_values(array_unique(array_merge($resolved['forceAddons'], $selection->enabledAddons)));
        $effectiveEnabledLayers = array_values(array_unique(array_merge($resolved['forceLayers'], $selection->enabledLayers)));

        $composer = $this->baseComposer($selection->version);

        $hyvaProject = trim($hyvaProject);
        if ($hyvaProject !== '') {
            $composer['repositories'][] = [
                'type' => 'composer',
                'url' => "https://hyva-themes.repo.packagist.com/{$hyvaProject}/",
            ];
        }

        // Add-ons: append to require.
        foreach ($effectiveAddons as $addon) {
            foreach ($this->defs->addonPackages($addon) as $pkg) {
                $composer['require'][$pkg] = '*';
            }
            $this->appendRepositories($composer, $this->defs->addonRepositories($addon));
        }
        // Non-stock layers that are enabled: append to require.
        foreach ($effectiveEnabledLayers as $layer) {
            if ($this->defs->isLayerStock($layer)) {
                continue;
            }
            foreach ($this->defs->layerPackages($layer) as $pkg) {
                $composer['require'][$pkg] = '*';
            }
            $this->appendRepositories($composer, $this->defs->layerRepositories($layer));
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
     * Add-on names forced on (locked) by the currently-selected profile-group options.
     *
     * @return list<string>
     */
    public function forcedAddons(Selection $selection): array
    {
        return array_values(array_unique($this->resolveProfileGroups($selection)['forceAddons']));
    }

    /**
     * Layer names forced on (locked) by the currently-selected profile-group options.
     *
     * @return list<string>
     */
    public function forcedLayers(Selection $selection): array
    {
        return array_values(array_unique($this->resolveProfileGroups($selection)['forceLayers']));
    }

    /**
     * Add-on names that the currently-selected profile-group options soft-default to ON
     * (auto-checked but user can override).
     *
     * @return list<string>
     */
    public function defaultedAddons(Selection $selection): array
    {
        return array_values(array_unique($this->resolveProfileGroups($selection)['defaultAddons']));
    }

    /**
     * Layer names that the currently-selected profile-group options soft-default to ON.
     *
     * @return list<string>
     */
    public function defaultedLayers(Selection $selection): array
    {
        return array_values(array_unique($this->resolveProfileGroups($selection)['defaultLayers']));
    }

    /**
     * Merge addon/layer-declared repositories into the composer array, deduping
     * against repositories already present (base mage-os repo, Hyvä, or earlier
     * addons that declared the same one).
     *
     * @param  list<array<string,mixed>>  $repos
     */
    private function appendRepositories(array &$composer, array $repos): void
    {
        $composer['repositories'] ??= [];
        foreach ($repos as $repo) {
            if (! in_array($repo, $composer['repositories'], true)) {
                $composer['repositories'][] = $repo;
            }
        }
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
     * @return array{forceAddons:list<string>, forceLayers:list<string>, defaultAddons:list<string>, defaultLayers:list<string>, disableSets:list<string>, disableLayers:list<string>}
     */
    private function resolveProfileGroups(Selection $selection): array
    {
        $forceAddons = $forceLayers = $defaultAddons = $defaultLayers = $disableSets = $disableLayers = [];

        foreach ($selection->profileGroups as $group => $optionName) {
            $option = $this->defs->profileGroupOption($group, $optionName);
            if ($option === null) {
                continue;
            }
            $defaultAddons = array_merge($defaultAddons, $option['enables']['addons'] ?? []);
            $defaultLayers = array_merge($defaultLayers, $option['enables']['layers'] ?? []);
            $forceAddons = array_merge($forceAddons, $option['forces']['addons'] ?? []);
            $forceLayers = array_merge($forceLayers, $option['forces']['layers'] ?? []);
            $disableSets = array_merge($disableSets, $option['disables']['sets'] ?? []);
            $disableLayers = array_merge($disableLayers, $option['disables']['layers'] ?? []);
        }

        return [
            'forceAddons' => $forceAddons,
            'forceLayers' => $forceLayers,
            'defaultAddons' => $defaultAddons,
            'defaultLayers' => $defaultLayers,
            'disableSets' => $disableSets,
            'disableLayers' => $disableLayers,
        ];
    }
}
