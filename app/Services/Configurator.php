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
        private readonly AddonVersionResolver $addonVersions,
        private readonly string $repositoryUrl,
    ) {}

    public function build(Selection $selection, string $hyvaProject = ''): array
    {
        $resolved = $this->resolveProfileGroups($selection);

        $disabledSets = array_values(array_unique(array_merge($resolved['disableSets'], $selection->disabledSets)));
        $disabledLayers = array_values(array_unique(array_merge($resolved['disableLayers'], $selection->disabledLayers)));
        // Forced items always go in, soft defaults only when echoed back via the form.
        $effectiveAddons = array_values(array_unique(array_merge(
            $resolved['forceAddons'],
            $selection->enabledAddons,
            $this->activeOptionSubtoggleAddons($selection),
        )));
        $effectiveEnabledLayers = array_values(array_unique(array_merge($resolved['forceLayers'], $selection->enabledLayers)));

        $composer = $this->baseComposer($selection->version);

        $hyvaProject = trim($hyvaProject);
        if ($hyvaProject !== '') {
            $composer['repositories'][] = [
                'type' => 'composer',
                'url' => "https://hyva-themes.repo.packagist.com/{$hyvaProject}/",
            ];
        }

        // Gate context — `requires:` blocks on individual package entries are
        // checked against this. Built once up front; the `package` gate reads
        // the running `require` map directly so order matters: gates that
        // depend on a package only fire after that package has been added.
        $ctx = [
            'addons' => array_flip($effectiveAddons),
            'layers' => array_flip($effectiveEnabledLayers),
            'disabledSets' => array_flip($disabledSets),
            'disabledLayers' => array_flip($disabledLayers),
        ];

        // Add-ons: append to require, pinning to the latest known version
        // (resolved offline by mageos:catalog:update). Falls back to "*" when
        // a package wasn't in the cached version map.
        foreach ($effectiveAddons as $addon) {
            foreach ($this->defs->addonPackageEntries($addon) as $entry) {
                if (! $this->packageAllowed($entry, $ctx, $composer['require'])) {
                    continue;
                }
                $pkg = $entry['name'];
                $composer['require'][$pkg] = $this->addonVersions->constraint($pkg) ?? '*';
            }
            $this->appendRepositories($composer, $this->defs->addonRepositories($addon));
        }
        // Non-stock layers that are enabled: append to require.
        foreach ($effectiveEnabledLayers as $layer) {
            if ($this->defs->isLayerStock($layer)) {
                continue;
            }
            foreach ($this->defs->layerPackageEntries($layer) as $entry) {
                if (! $this->packageAllowed($entry, $ctx, $composer['require'])) {
                    continue;
                }
                $pkg = $entry['name'];
                $composer['require'][$pkg] = $this->addonVersions->constraint($pkg) ?? '*';
            }
            $this->appendRepositories($composer, $this->defs->layerRepositories($layer));
        }
        ksort($composer['require']);

        // Disabled sets and disabled stock-layers: append to replace.
        // Gating on `package:` for replace entries lets a stock layer (e.g.
        // graphql) skip replacing a package whose root module has already
        // been replaced away — so the replace map only mentions packages
        // that would otherwise be installed.
        $replace = $composer['replace'] ?? [];
        foreach ($disabledSets as $set) {
            foreach ($this->defs->setPackageEntries($set) as $entry) {
                if (! $this->packageAllowed($entry, $ctx, $composer['require'])) {
                    continue;
                }
                $replace[$entry['name']] = '*';
            }
            // Parent disabled → subtoggle packages also go to replace.
            foreach ($this->defs->setSubtoggles($set) as $sub) {
                foreach (Definitions::normalizePackages($sub['packages'] ?? []) as $entry) {
                    if (! $this->packageAllowed($entry, $ctx, $composer['require'])) {
                        continue;
                    }
                    $replace[$entry['name']] = '*';
                }
            }
        }
        // Subtoggles whose parent is still enabled: only their own packages go to replace.
        $disabledSetMap = array_flip($disabledSets);
        foreach ($selection->disabledSubtoggles as $key) {
            [$setName, $subName] = array_pad(explode('.', $key, 2), 2, '');
            if ($subName === '' || isset($disabledSetMap[$setName])) {
                continue;
            }
            foreach ($this->defs->subtogglePackageEntries($setName, $subName) as $entry) {
                if (! $this->packageAllowed($entry, $ctx, $composer['require'])) {
                    continue;
                }
                $replace[$entry['name']] = '*';
            }
        }
        foreach ($disabledLayers as $layer) {
            if (! $this->defs->isLayerStock($layer)) {
                continue;
            }
            foreach ($this->defs->layerPackageEntries($layer) as $entry) {
                if (! $this->packageAllowed($entry, $ctx, $composer['require'])) {
                    continue;
                }
                $replace[$entry['name']] = '*';
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
        return array_values(array_unique(array_merge(
            $this->resolveProfileGroups($selection)['forceAddons'],
            // Addons pulled in by an enabled option-subtoggle are likewise out of
            // the user's direct control in the Add-ons panel — they show up as
            // forced-checked there, with the subtoggle being the single source
            // of truth for their on/off state.
            $this->activeOptionSubtoggleAddons($selection),
        )));
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
    /**
     * Evaluate a package entry's `requires:` gate against the build context.
     * Schema and supported keys are documented on
     * {@see Definitions::normalizePackages()}.
     *
     * @param  array{name:string,requires?:array<string,string>}  $entry
     * @param  array{addons:array<string,int>,layers:array<string,int>,disabledSets:array<string,int>,disabledLayers:array<string,int>}  $ctx
     * @param  array<string,string>  $require  running require map (for `requires.package` gates)
     */
    private function packageAllowed(array $entry, array $ctx, array $require): bool
    {
        $req = $entry['requires'] ?? null;
        if (! is_array($req)) {
            return true;
        }
        if (isset($req['addon']) && ! isset($ctx['addons'][$req['addon']])) {
            return false;
        }
        if (isset($req['layer']) && ! isset($ctx['layers'][$req['layer']])) {
            return false;
        }
        if (isset($req['set']) && isset($ctx['disabledSets'][$req['set']])) {
            return false;
        }
        if (isset($req['package']) && ! isset($require[$req['package']])) {
            return false;
        }

        return true;
    }

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

        // Merge in the allow-plugins required by Mage-OS / Magento composer plugins.
        // Upstream project-community-edition currently ships no `config` block; we still
        // merge defensively so we don't clobber it if that ever changes.
        $allowPlugins = [
            'php-http/discovery' => true,
            'mage-os/composer-dependency-version-audit-plugin' => true,
            'mage-os/composer-root-update-plugin' => true,
            'mage-os/inventory-composer-installer' => true,
            'dealerdirect/phpcodesniffer-composer-installer' => true,
            'mage-os/magento-composer-installer' => true,
        ];
        $composer['config'] ??= [];
        $composer['config']['allow-plugins'] = ($composer['config']['allow-plugins'] ?? []) + $allowPlugins;

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
            // Option's requires constraint not satisfied → treat as if the group's
            // default option were picked. UI prevents this in normal flow; this is
            // the belt-and-braces fallback for saved-config replay or CLI flag combos.
            if (! $this->defs->optionMeetsRequires($group, $optionName, $selection->profileGroups)) {
                $defaultName = $this->defs->defaultProfileGroupOption($group);
                if ($defaultName === null || $defaultName === $optionName) {
                    continue;
                }
                $option = $this->defs->profileGroupOption($group, $defaultName) ?? [];
                $optionName = $defaultName;
            }
            // If the option declares variants, the active variant carries the
            // effects (enables/forces/etc.) — the parent option is just a label.
            if (! empty($option['variants'])) {
                $variantName = $this->defs->optionActiveVariant($group, $optionName, $selection->profileGroups, $selection->optionVariants);
                if ($variantName === null) {
                    continue;
                }
                $option = $this->defs->optionVariants($group, $optionName)[$variantName] ?? [];
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

    /**
     * Resolve the addon names contributed by enabled option-subtoggles.
     * A subtoggle only contributes if (a) its parent option is the currently-picked
     * radio in its group AND (b) the option's `requires` constraint is satisfied.
     *
     * @return list<string>
     */
    private function activeOptionSubtoggleAddons(Selection $selection): array
    {
        $out = [];
        foreach ($selection->enabledOptionSubtoggles as $key) {
            $parts = explode('.', $key);
            if (count($parts) === 3) {
                [$group, $option, $sub] = $parts;
                $variant = null;
            } elseif (count($parts) === 4) {
                [$group, $option, $variant, $sub] = $parts;
            } else {
                continue;
            }
            if (($selection->profileGroups[$group] ?? null) !== $option) {
                continue;
            }
            if (! $this->defs->optionMeetsRequires($group, $option, $selection->profileGroups)) {
                continue;
            }
            $optDef = $this->defs->profileGroupOption($group, $option) ?? [];
            if (! empty($optDef['variants'])) {
                if ($variant === null) {
                    continue; // 3-segment key on a variant-bearing option is malformed
                }
                $activeVariant = $this->defs->optionActiveVariant($group, $option, $selection->profileGroups, $selection->optionVariants);
                if ($activeVariant !== $variant) {
                    continue;
                }
                $variantDef = $this->defs->optionVariants($group, $option)[$variant] ?? [];
                $subDef = collect($variantDef['subtoggles'] ?? [])->firstWhere('name', $sub);
                $addons = $subDef['addons'] ?? [];
            } else {
                if ($variant !== null) {
                    continue; // 4-segment key on a non-variant option is malformed
                }
                $addons = $this->defs->optionSubtoggleAddons($group, $option, $sub);
            }
            foreach ($addons as $addon) {
                $out[] = $addon;
            }
        }

        return $out;
    }
}
