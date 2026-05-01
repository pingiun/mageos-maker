<?php

namespace App\Services;

/**
 * Strongly-typed view of the YAML definitions: sets, layers, profile-groups, profiles.
 */
class Definitions
{
    /**
     * @param  array<string, array{name:string, label:string, description?:string, packages:list<string>}>  $sets    Stock module groups; only meaningful when DISABLED (added to `replace`).
     * @param  array<string, array{name:string, label:string, description?:string, packages:list<string>}>  $layers  Stock cross-cutting concerns; only meaningful when DISABLED.
     * @param  array<string, array{name:string, label:string, description?:string, packages:list<string>}>  $addons  Extra packages NOT in stock Mage-OS; only meaningful when ENABLED (added to `require`).
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

    public function layerPackages(string $name): array
    {
        return $this->layers[$name]['packages'] ?? [];
    }

    public function addonPackages(string $name): array
    {
        return $this->addons[$name]['packages'] ?? [];
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
