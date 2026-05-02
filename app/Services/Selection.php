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
 *  - Subtoggles are finer-grained switches inside a set (e.g. 2FA's Duo
 *    provider). Tracked positively as `disabledSubtoggles` (default empty).
 *    Only meaningful when the parent set is enabled.
 */
class Selection
{
    public function __construct(
        public readonly string $version,
        public readonly ?string $profile,
        public readonly array $disabledSets,
        public readonly array $disabledLayers,
        public readonly array $enabledLayers,
        public readonly array $enabledAddons,
        public readonly array $profileGroups,
        /** @var list<string> "<setName>.<subName>" keys */
        public readonly array $disabledSubtoggles = [],
        /**
         * Profile-group option subtoggles that are currently ON.
         * Format: "<groupName>.<optionName>.<subName>".
         * Positive list (matches addon "opt-in by default" convention; entries with
         * `default: true` get auto-added at hydrate time).
         *
         * @var list<string>
         */
        public readonly array $enabledOptionSubtoggles = [],
        /**
         * Per-option variant pick: ['<group>.<option>' => '<variantName>', ...].
         * Only relevant for options that declare a `variants` block. The Configurator
         * resolves to the option's default variant when no explicit pick exists or
         * when the picked variant's `requires` aren't met.
         *
         * @var array<string,string>
         */
        public readonly array $optionVariants = [],
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
            enabledLayers: [],
            enabledAddons: [],
            profileGroups: $profileGroups,
            disabledSubtoggles: [],
            enabledOptionSubtoggles: $defs->defaultOnOptionSubtoggleKeys(),
            optionVariants: [],
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
            enabledLayers: array_values($data['enabledLayers'] ?? []),
            enabledAddons: array_values($data['enabledAddons'] ?? []),
            profileGroups: $data['profileGroups'] ?? [],
            disabledSubtoggles: array_values($data['disabledSubtoggles'] ?? []),
            enabledOptionSubtoggles: array_values($data['enabledOptionSubtoggles'] ?? $defs->defaultOnOptionSubtoggleKeys()),
            optionVariants: $data['optionVariants'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'profile' => $this->profile,
            'disabledSets' => $this->disabledSets,
            'disabledLayers' => $this->disabledLayers,
            'enabledLayers' => $this->enabledLayers,
            'enabledAddons' => $this->enabledAddons,
            'profileGroups' => $this->profileGroups,
            'disabledSubtoggles' => $this->disabledSubtoggles,
            'enabledOptionSubtoggles' => $this->enabledOptionSubtoggles,
            'optionVariants' => $this->optionVariants,
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
            enabledLayers: array_values(array_unique(array_merge($this->enabledLayers, $sel['enabledLayers'] ?? []))),
            enabledAddons: array_values(array_unique(array_merge($this->enabledAddons, $sel['enabledAddons'] ?? []))),
            profileGroups: array_merge($this->profileGroups, $sel['profileGroups'] ?? []),
            disabledSubtoggles: array_values(array_unique(array_merge($this->disabledSubtoggles, $sel['disabledSubtoggles'] ?? []))),
            enabledOptionSubtoggles: array_values(array_unique(array_merge($this->enabledOptionSubtoggles, $sel['enabledOptionSubtoggles'] ?? []))),
            optionVariants: array_merge($this->optionVariants, $sel['optionVariants'] ?? []),
        );
    }
}
