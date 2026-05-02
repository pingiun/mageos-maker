<?php

namespace App\Services;

/**
 * Strongly-typed view of the YAML definitions: sets, layers, profile-groups, profiles.
 */
class Definitions
{
    /**
     * @param  array<string, array{name:string, label:string, description?:string, packages:list<string>}>  $sets    Stock module groups; only meaningful when DISABLED (added to `replace`).
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
        return $this->sets[$name]['packages'] ?? [];
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
        return $this->setSubtoggles($set)[$sub]['packages'] ?? [];
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
        return $this->layers[$name]['packages'] ?? [];
    }

    public function addonPackages(string $name): array
    {
        return $this->addons[$name]['packages'] ?? [];
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
