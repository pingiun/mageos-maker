<?php

namespace App\Services;

/**
 * The user's choices. All sets/layers default to enabled — only listed disables matter.
 *
 * @property-read list<string> $enabledSets   Sets explicitly enabled (e.g. by a profile-group option).
 * @property-read list<string> $disabledSets  Sets the user toggled off.
 * @property-read list<string> $enabledLayers
 * @property-read list<string> $disabledLayers
 * @property-read array<string,string> $profileGroups  Map of group name -> chosen option name.
 */
class Selection
{
    public function __construct(
        public readonly string $version,
        public readonly ?string $profile,
        public readonly array $enabledSets,
        public readonly array $disabledSets,
        public readonly array $enabledLayers,
        public readonly array $disabledLayers,
        public readonly array $profileGroups,
    ) {}

    public static function default(string $version, Definitions $defs): self
    {
        $profileName = $defs->defaultProfile();
        $profileGroups = [];
        foreach (array_keys($defs->profileGroups) as $group) {
            $default = $defs->defaultProfileGroupOption($group);
            if ($default !== null) {
                $profileGroups[$group] = $default;
            }
        }

        $self = new self(
            version: $version,
            profile: $profileName,
            enabledSets: [],
            disabledSets: [],
            enabledLayers: [],
            disabledLayers: [],
            profileGroups: $profileGroups,
        );

        if ($profileName !== null && isset($defs->profiles[$profileName])) {
            $self = $self->applyProfile($defs->profiles[$profileName]);
        }

        return $self;
    }

    public static function fromArray(array $data, string $defaultVersion, Definitions $defs): self
    {
        return new self(
            version: $data['version'] ?? $defaultVersion,
            profile: $data['profile'] ?? $defs->defaultProfile(),
            enabledSets: array_values($data['enabledSets'] ?? []),
            disabledSets: array_values($data['disabledSets'] ?? []),
            enabledLayers: array_values($data['enabledLayers'] ?? []),
            disabledLayers: array_values($data['disabledLayers'] ?? []),
            profileGroups: $data['profileGroups'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'profile' => $this->profile,
            'enabledSets' => $this->enabledSets,
            'disabledSets' => $this->disabledSets,
            'enabledLayers' => $this->enabledLayers,
            'disabledLayers' => $this->disabledLayers,
            'profileGroups' => $this->profileGroups,
        ];
    }

    public function applyProfile(array $profile): self
    {
        $sel = $profile['selection'] ?? [];
        return new self(
            version: $this->version,
            profile: $profile['name'] ?? $this->profile,
            enabledSets: array_values(array_unique(array_merge($this->enabledSets, $sel['enabledSets'] ?? []))),
            disabledSets: array_values(array_unique(array_merge($this->disabledSets, $sel['disabledSets'] ?? []))),
            enabledLayers: array_values(array_unique(array_merge($this->enabledLayers, $sel['enabledLayers'] ?? []))),
            disabledLayers: array_values(array_unique(array_merge($this->disabledLayers, $sel['disabledLayers'] ?? []))),
            profileGroups: array_merge($this->profileGroups, $sel['profileGroups'] ?? []),
        );
    }
}
