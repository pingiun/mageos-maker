<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

class DefinitionLoader
{
    public function __construct(private readonly string $basePath) {}

    public function load(): Definitions
    {
        return new Definitions(
            sets: $this->readDir('sets'),
            layers: $this->readDir('layers'),
            addons: $this->readDir('addons'),
            profileGroups: $this->readDir('profile-groups', byOrder: true),
            profiles: $this->readDir('profiles'),
        );
    }

    private function readDir(string $sub, bool $byOrder = false): array
    {
        $dir = $this->basePath.'/'.$sub;
        if (! is_dir($dir)) {
            return [];
        }

        $items = [];
        foreach (glob($dir.'/*.yaml') ?: [] as $file) {
            $parsed = Yaml::parseFile($file);
            if (! is_array($parsed) || ! isset($parsed['name'])) {
                throw new \RuntimeException("Definition $file missing 'name'");
            }
            $items[$parsed['name']] = $parsed;
        }
        if ($byOrder) {
            uasort($items, fn ($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999) ?: strcmp($a['name'], $b['name']));
        } else {
            ksort($items);
        }
        return $items;
    }
}
