<?php

namespace App\Services;

/**
 * The user's choices.
 *
 *  - Sets and layers represent stock Mage-OS modules — enabled by default;
 *    only the `disabledSets` / `disabledLayers` lists matter (those go to `replace`).
 *  - Add-ons are extra packages outside stock Mage-OS — disabled by default;
 *    only the `enabledAddons` list matters (those go to `require`).
 *  - Profile-group options can pull add-ons in automatically (and may also
 *    disable sets/layers); those forced add-ons are computed at build time
 *    and aren't stored in `enabledAddons`.
 */
class Selection
{
    public function __construct(
        public readonly string $version,
        public readonly ?string $profile,
        public readonly array $disabledSets,
        public readonly array $disabledLayers,
        public readonly array $enabledAddons,
        public readonly array $profileGroups,
    ) {}

    public static function default(string $version, Definitions $defs): self
    {
        $profileGroups = [];
        foreach (array_keys($defs->profileGroups) as $group) {
            $default = $defs->defaultProfileGroupOption($group);
            if ($default !== null) {
                $profileGroups[$group] = $default;
            }
        }

        $self = new self(
            version: $version,
            profile: $defs->defaultProfile(),
            disabledSets: [],
            disabledLayers: [],
            enabledAddons: [],
            profileGroups: $profileGroups,
        );

        if ($self->profile !== null && isset($defs->profiles[$self->profile])) {
            $self = $self->applyProfile($defs->profiles[$self->profile]);
        }

        return $self;
    }

    public static function fromArray(array $data, string $defaultVersion, Definitions $defs): self
    {
        return new self(
            version: $data['version'] ?? $defaultVersion,
            profile: $data['profile'] ?? $defs->defaultProfile(),
            disabledSets: array_values($data['disabledSets'] ?? []),
            disabledLayers: array_values($data['disabledLayers'] ?? []),
            enabledAddons: array_values($data['enabledAddons'] ?? []),
            profileGroups: $data['profileGroups'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'profile' => $this->profile,
            'disabledSets' => $this->disabledSets,
            'disabledLayers' => $this->disabledLayers,
            'enabledAddons' => $this->enabledAddons,
            'profileGroups' => $this->profileGroups,
        ];
    }

    public function applyProfile(array $profile): self
    {
        $sel = $profile['selection'] ?? [];
        return new self(
            version: $this->version,
            profile: $profile['name'] ?? $this->profile,
            disabledSets: array_values(array_unique(array_merge($this->disabledSets, $sel['disabledSets'] ?? []))),
            disabledLayers: array_values(array_unique(array_merge($this->disabledLayers, $sel['disabledLayers'] ?? []))),
            enabledAddons: array_values(array_unique(array_merge($this->enabledAddons, $sel['enabledAddons'] ?? []))),
            profileGroups: array_merge($this->profileGroups, $sel['profileGroups'] ?? []),
        );
    }
}
