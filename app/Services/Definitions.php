<?php

namespace App\Services;

/**
 * Strongly-typed view of the YAML definitions: sets, layers, profile-groups, profiles.
 */
class Definitions
{
    /**
     * @param  array<string, array{name:string, label:string, description?:string, packages:list<string>}>  $sets  Stock module groups; only meaningful when DISABLED (added to `replace`).
     * @param  array<string, array{name:string, label:string, description?:string, stock?:bool, packages:list<string>, repositories?:list<array<string,mixed>>}>  $layers  Stock cross-cutting concerns; only meaningful when DISABLED. Non-stock layers may declare extra composer repositories.
     * @param  array<string, array{name:string, label:string, description?:string, packages:list<string>, repositories?:list<array<string,mixed>>}>  $addons  Extra packages NOT in stock Mage-OS; only meaningful when ENABLED (added to `require`). May declare extra composer repositories.
     * @param  array<string, array{name:string, label:string, description?:string, options:list<array<string,mixed>>}>  $profileGroups
     * @param  array<string, array{name:string, label:string, description?:string, default?:bool, selection:array<string,mixed>}>  $profiles
     */
    public function __construct(
        public readonly array $sets,
        public readonly array $layers,
        public readonly array $addons,
        public readonly array $profileGroups,
        public readonly array $profiles,
    ) {}

    public function setPackages(string $name): array
    {
        return self::entryNames($this->sets[$name]['packages'] ?? []);
    }

    /**
     * Normalised package entries for a set. Each entry is `{name: string,
     * requires?: array}` where `requires` (when present) gates whether the
     * package actually contributes — see {@see normalizePackages()}.
     *
     * @return list<array{name:string,requires?:array<string,string>}>
     */
    public function setPackageEntries(string $name): array
    {
        return self::normalizePackages($this->sets[$name]['packages'] ?? []);
    }

    /**
     * Subtoggles defined under a set: a list of finer-grained on/off switches
     * that only matter when the parent set is enabled. Each subtoggle has the
     * shape `{name, label, description?, packages: list<string>}`.
     *
     * @return array<string,array{name:string,label:string,description?:string,packages:list<string>}>
     */
    public function setSubtoggles(string $name): array
    {
        $out = [];
        foreach ($this->sets[$name]['subtoggles'] ?? [] as $sub) {
            $out[$sub['name']] = $sub;
        }

        return $out;
    }

    public function subtogglePackages(string $set, string $sub): array
    {
        return self::entryNames($this->setSubtoggles($set)[$sub]['packages'] ?? []);
    }

    /** @return list<array{name:string,requires?:array<string,string>}> */
    public function subtogglePackageEntries(string $set, string $sub): array
    {
        return self::normalizePackages($this->setSubtoggles($set)[$sub]['packages'] ?? []);
    }

    /** All "set.sub" keys across every defined set, used for default/complement computations. */
    public function allSubtoggleKeys(): array
    {
        $keys = [];
        foreach ($this->sets as $setName => $_def) {
            foreach (array_keys($this->setSubtoggles($setName)) as $subName) {
                $keys[] = "$setName.$subName";
            }
        }

        return $keys;
    }

    public function layerPackages(string $name): array
    {
        return self::entryNames($this->layers[$name]['packages'] ?? []);
    }

    /** @return list<array{name:string,requires?:array<string,string>}> */
    public function layerPackageEntries(string $name): array
    {
        return self::normalizePackages($this->layers[$name]['packages'] ?? []);
    }

    public function addonPackages(string $name): array
    {
        return self::entryNames($this->addons[$name]['packages'] ?? []);
    }

    /** @return list<array{name:string,requires?:array<string,string>}> */
    public function addonPackageEntries(string $name): array
    {
        return self::normalizePackages($this->addons[$name]['packages'] ?? []);
    }

    /**
     * A YAML `packages:` entry can be either a string (always-included) or a
     * map `{name: 'pkg/name', requires: {...}}`. The `requires` block is the
     * per-package gate evaluated by {@see Configurator}; today it supports:
     *   - addon:   <name>          → only contribute if the addon is enabled
     *   - layer:   <name>          → only contribute if that layer is enabled
     *   - set:     <name>          → only contribute if the set is enabled
     *                                 (i.e. NOT in disabledSets)
     *   - package: vendor/name     → only contribute if the package is in
     *                                 the running require map (i.e. its base
     *                                 module hasn't been replaced away)
     *
     * Entries without `requires` always contribute, preserving the legacy
     * "list of strings" behaviour.
     *
     * @param  list<string|array<string,mixed>>  $entries
     * @return list<array{name:string,requires?:array<string,string>}>
     */
    public static function normalizePackages(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            if (is_string($e)) {
                $out[] = ['name' => $e];

                continue;
            }
            if (! is_array($e) || ! isset($e['name']) || ! is_string($e['name'])) {
                continue;
            }
            $entry = ['name' => $e['name']];
            if (isset($e['requires']) && is_array($e['requires'])) {
                $entry['requires'] = $e['requires'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param  list<string|array<string,mixed>>  $entries
     * @return list<string>
     */
    private static function entryNames(array $entries): array
    {
        return array_map(fn ($e) => $e['name'], self::normalizePackages($entries));
    }

    /**
     * Extra composer repositories an addon needs (e.g. packagist for packages
     * not on the Mage-OS mirror). Each entry is a raw composer repository
     * object — `{type: 'composer', url: ...}`, `{type: 'vcs', url: ...}`, etc.
     *
     * @return list<array<string,mixed>>
     */
    public function addonRepositories(string $name): array
    {
        return $this->addons[$name]['repositories'] ?? [];
    }

    /**
     * Extra composer repositories a non-stock layer needs. Same shape as
     * {@see addonRepositories()}.
     *
     * @return list<array<string,mixed>>
     */
    public function layerRepositories(string $name): array
    {
        return $this->layers[$name]['repositories'] ?? [];
    }

    /**
     * Whether a layer's packages are part of stock Mage-OS.
     * Stock layers are subtractive (disable adds to `replace`).
     * Non-stock layers are additive (enable adds to `require`).
     * Defaults to true when the YAML omits the flag.
     */
    /**
     * Whether a set may be disabled by the user. Sets with `removable: false`
     * are forced on — currently used for sets the Modulargento removal matrix
     * proved cannot be cleanly cut out of stock Mage-OS without a patch module.
     * Defaults to true when the YAML omits the flag.
     */
    public function isSetRemovable(string $name): bool
    {
        if (! array_key_exists($name, $this->sets)) {
            return true;
        }
        return ($this->sets[$name]['removable'] ?? true) !== false;
    }

    public function isLayerStock(string $name): bool
    {
        return ! array_key_exists($name, $this->layers)
            ? true
            : ($this->layers[$name]['stock'] ?? true) !== false;
    }

    public function profileGroupOption(string $group, string $option): ?array
    {
        foreach ($this->profileGroups[$group]['options'] ?? [] as $opt) {
            if ($opt['name'] === $option) {
                return $opt;
            }
        }

        return null;
    }

    /**
     * True iff the given profile-group option's `requires.profileGroups`
     * constraints are satisfied by the current profileGroups state.
     * An option without a `requires` block is always available.
     *
     * @param  array<string,string>  $profileGroups  current selection state
     */
    public function optionMeetsRequires(string $group, string $option, array $profileGroups): bool
    {
        $opt = $this->profileGroupOption($group, $option);
        if ($opt === null) {
            return false;
        }
        $required = $opt['requires']['profileGroups'] ?? [];
        foreach ($required as $g => $expected) {
            if (($profileGroups[$g] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Soft suggestion: when the current selection matches the option's
     * `preferAlternative.when` block, the UI should hint that a different
     * option in the same group is the better fit. Doesn't gate availability.
     *
     * Schema:
     *   preferAlternative:
     *     when:
     *       profileGroups: { theme: hyva }
     *     use: loki-checkout-hyva
     *     reason: more performant on Hyvä   # optional
     *
     * @param  array<string,string>  $profileGroups
     * @return array{use:string,reason?:string}|null
     */
    public function optionPreferAlternative(string $group, string $option, array $profileGroups): ?array
    {
        $opt = $this->profileGroupOption($group, $option);
        $pref = $opt['preferAlternative'] ?? null;
        if (! is_array($pref) || ! isset($pref['use'])) {
            return null;
        }
        $when = $pref['when']['profileGroups'] ?? [];
        if ($when === []) {
            return null;
        }
        foreach ($when as $g => $expected) {
            if (($profileGroups[$g] ?? null) !== $expected) {
                return null;
            }
        }

        return ['use' => $pref['use']] + (isset($pref['reason']) ? ['reason' => $pref['reason']] : []);
    }

    /**
     * Subtoggles defined under a profile-group option, keyed by sub name.
     * Each subtoggle: `{name, label, description?, addons: list<string>, default?: bool}`.
     *
     * @return array<string,array{name:string,label:string,description?:string,addons:list<string>,default?:bool}>
     */
    public function optionSubtoggles(string $group, string $option): array
    {
        $opt = $this->profileGroupOption($group, $option);
        $out = [];
        foreach ($opt['subtoggles'] ?? [] as $sub) {
            $out[$sub['name']] = $sub;
        }

        return $out;
    }

    public function optionSubtoggleAddons(string $group, string $option, string $sub): array
    {
        return $this->optionSubtoggles($group, $option)[$sub]['addons'] ?? [];
    }

    /**
     * Variants under a profile-group option. Each variant is option-shaped:
     * `{name, label, requires?, preferAlternative?, enables?, subtoggles?}`.
     * Returned keyed by variant name.
     *
     * @return array<string,array<string,mixed>>
     */
    public function optionVariants(string $group, string $option): array
    {
        $opt = $this->profileGroupOption($group, $option);
        $out = [];
        foreach ($opt['variants'] ?? [] as $v) {
            $out[$v['name']] = $v;
        }

        return $out;
    }

    /**
     * The variant that's currently active for an option. Resolution order:
     *   1. user's explicit pick (from $userPicks) if its `requires` is met
     *   2. variant flagged with `default: true` if its `requires` is met
     *   3. first variant whose `requires` is met
     *   4. null if none qualify
     *
     * The Livewire layer is expected to clear stale user picks when the
     * profileGroups change (so picks don't survive a theme switch that
     * would otherwise silently keep an inappropriate variant). This helper
     * stays pure — it never mutates inputs.
     *
     * @param  array<string,string>  $profileGroups
     * @param  array<string,string>  $userPicks  map "<group>.<option>" → variant name
     */
    public function optionActiveVariant(string $group, string $option, array $profileGroups, array $userPicks = []): ?string
    {
        $variants = $this->optionVariants($group, $option);
        if ($variants === []) {
            return null;
        }
        $meets = function (array $v) use ($profileGroups): bool {
            foreach (($v['requires']['profileGroups'] ?? []) as $g => $expected) {
                if (($profileGroups[$g] ?? null) !== $expected) {
                    return false;
                }
            }

            return true;
        };

        $picked = $userPicks["$group.$option"] ?? null;
        if ($picked !== null && isset($variants[$picked]) && $meets($variants[$picked])) {
            return $picked;
        }
        foreach ($variants as $name => $v) {
            if (! empty($v['default']) && $meets($v)) {
                return $name;
            }
        }
        foreach ($variants as $name => $v) {
            if ($meets($v)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * "<group>.<option>" keys for every option that declares variants.
     * Used to seed Selection's optionVariants map at hydrate time.
     *
     * @return list<string>
     */
    public function allVariantOptionKeys(): array
    {
        $keys = [];
        foreach ($this->profileGroups as $groupName => $group) {
            foreach ($group['options'] ?? [] as $opt) {
                if (! empty($opt['variants'])) {
                    $keys[] = "$groupName.{$opt['name']}";
                }
            }
        }

        return $keys;
    }

    /**
     * "<group>.<option>.<sub>" keys for every option-subtoggle that defaults to ON.
     * Used to seed the Livewire component's enabledOptionSubtoggles list at hydrate time.
     *
     * @return list<string>
     */
    public function defaultOnOptionSubtoggleKeys(): array
    {
        $keys = [];
        foreach ($this->profileGroups as $groupName => $group) {
            foreach ($group['options'] ?? [] as $opt) {
                foreach ($opt['subtoggles'] ?? [] as $sub) {
                    if (! empty($sub['default'])) {
                        $keys[] = "$groupName.{$opt['name']}.{$sub['name']}";
                    }
                }
                // Variants can also declare default-on subtoggles. Use a 4-segment
                // key (group.option.variant.sub) to disambiguate variants of the
                // same option that happen to share a subtoggle name.
                foreach ($opt['variants'] ?? [] as $variant) {
                    foreach ($variant['subtoggles'] ?? [] as $sub) {
                        if (! empty($sub['default'])) {
                            $keys[] = "$groupName.{$opt['name']}.{$variant['name']}.{$sub['name']}";
                        }
                    }
                }
            }
        }

        return $keys;
    }

    public function defaultProfileGroupOption(string $group): ?string
    {
        foreach ($this->profileGroups[$group]['options'] ?? [] as $opt) {
            if (! empty($opt['default'])) {
                return $opt['name'];
            }
        }

        return null;
    }

    public function defaultProfile(): ?string
    {
        foreach ($this->profiles as $name => $profile) {
            if (! empty($profile['default'])) {
                return $name;
            }
        }

        return array_key_first($this->profiles);
    }
}
