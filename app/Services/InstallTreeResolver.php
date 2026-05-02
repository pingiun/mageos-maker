<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Loads pre-baked dependency graphs from disk and resolves the reachable
 * install set for a Selection via in-memory BFS. No Composer at runtime.
 *
 * Storage layout (under storage/app/private/graphs/):
 *   <version>/base.json
 *   <version>/options/<group>/<option>.json
 *
 * @see plans/02-install-tree.md
 */
class InstallTreeResolver
{
    /** Parsed graphs cached per process to amortize file read + json_decode across requests. */
    private static array $graphCache = [];

    public function __construct(
        private readonly Definitions $defs,
        private readonly string $graphsDir = 'graphs',
    ) {}

    /**
     * @return array{
     *   version:string, count:int, missing:bool, fallbackVersion:?string,
     *   packages:list<array{name:string,version:string,type:string}>,
     *   byType:array<string,int>,
     *   disabledHits:list<string>
     * }
     */
    public function resolve(Selection $sel): array
    {
        $base = $this->loadBase($sel->version);
        $fallbackVersion = null;
        $missing = false;
        if ($base === null) {
            $fallbackVersion = $this->latestBakedVersion();
            if ($fallbackVersion === null) {
                return [
                    'version' => $sel->version,
                    'count' => 0,
                    'missing' => true,
                    'fallbackVersion' => null,
                    'packages' => [],
                    'byType' => [],
                    'disabledHits' => [],
                ];
            }
            $missing = true;
            $base = $this->loadBase($fallbackVersion);
            if ($base === null) {
                return [
                    'version' => $sel->version, 'count' => 0, 'missing' => true,
                    'fallbackVersion' => $fallbackVersion, 'packages' => [],
                    'byType' => [], 'disabledHits' => [],
                ];
            }
        }

        $rootRequires = $base['rootRequires'];
        $packages = $base['packages'];

        // Apply add-on (additive) requires from non-default profile-group options
        // and from explicitly-enabled add-ons.
        foreach ($sel->profileGroups as $group => $option) {
            $defaultOption = $this->defs->defaultProfileGroupOption($group);
            if ($defaultOption === $option) {
                continue;
            }
            $delta = $this->loadDelta($base['version'], $group, $option);
            if ($delta === null) {
                continue;
            }
            foreach ($delta['addRequires'] ?? [] as $req) {
                if (! in_array($req, $rootRequires, true)) {
                    $rootRequires[] = $req;
                }
            }
            // Delta wins on package conflict.
            $packages = ($delta['addPackages'] ?? []) + $packages;
        }

        $disabled = $this->disabledPackageMap($sel);
        [$visited, $hits] = $this->bfs($rootRequires, $packages, $disabled);

        $resultPackages = [];
        $byType = [];
        foreach ($visited as $name) {
            $node = $packages[$name] ?? null;
            if ($node === null) {
                // Unknown root require (e.g. a non-stock layer not yet pre-baked).
                continue;
            }
            $type = $node['type'] ?? 'library';
            $resultPackages[] = [
                'name' => $name,
                'version' => $node['version'] ?? '?',
                'type' => $type,
            ];
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        // Stable order: by type then name, so the UI groups consistently.
        usort($resultPackages, fn ($a, $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);
        ksort($byType);

        return [
            'version' => $base['version'],
            'count' => count($resultPackages),
            'missing' => $missing,
            'fallbackVersion' => $missing ? $fallbackVersion : null,
            'packages' => $resultPackages,
            'byType' => $byType,
            'disabledHits' => array_keys($hits),
        ];
    }

    /**
     * Map of package names that the selection disables (set-or-layer membership).
     * Returns ['vendor/pkg' => true, ...] for O(1) lookup during BFS.
     *
     * @return array<string,bool>
     */
    private function disabledPackageMap(Selection $sel): array
    {
        $out = [];
        foreach ($sel->disabledSets as $set) {
            foreach ($this->defs->setPackages($set) as $pkg) {
                $out[$pkg] = true;
            }
        }
        foreach ($sel->disabledLayers as $layer) {
            if (! $this->defs->isLayerStock($layer)) {
                continue;
            }
            foreach ($this->defs->layerPackages($layer) as $pkg) {
                $out[$pkg] = true;
            }
        }
        return $out;
    }

    /**
     * Iterative BFS with a head-pointer FIFO (avoids array_shift cost).
     * Returns [visitedInOrder, disabledHitsThatWerePruned].
     *
     * @param  list<string>  $roots
     * @param  array<string,array{requires?:list<string>}>  $packages
     * @param  array<string,bool>  $disabled
     * @return array{0:list<string>,1:array<string,bool>}
     */
    private function bfs(array $roots, array $packages, array $disabled): array
    {
        $visited = [];
        $seen = [];
        $hits = [];
        $queue = [];
        foreach ($roots as $r) {
            $queue[] = $r;
        }

        for ($i = 0; $i < count($queue); $i++) {
            $name = $queue[$i];
            if (isset($seen[$name])) {
                continue;
            }
            if (isset($disabled[$name])) {
                $hits[$name] = true;
                $seen[$name] = true;
                continue;
            }
            $seen[$name] = true;
            $visited[] = $name;
            foreach ($packages[$name]['requires'] ?? [] as $dep) {
                if (! isset($seen[$dep])) {
                    $queue[] = $dep;
                }
            }
        }
        return [$visited, $hits];
    }

    public function loadBase(string $version): ?array
    {
        return $this->loadCached($version.'/base.json');
    }

    public function loadDelta(string $version, string $group, string $option): ?array
    {
        return $this->loadCached("$version/options/$group/$option.json");
    }

    private function loadCached(string $relativePath): ?array
    {
        if (array_key_exists($relativePath, self::$graphCache)) {
            return self::$graphCache[$relativePath];
        }
        $disk = Storage::disk('local');
        $full = $this->graphsDir.'/'.$relativePath;
        if (! $disk->exists($full)) {
            return self::$graphCache[$relativePath] = null;
        }
        $raw = $disk->get($full);
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        return self::$graphCache[$relativePath] = $data;
    }

    public function latestBakedVersion(): ?string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($this->graphsDir)) {
            return null;
        }
        $versions = [];
        foreach ($disk->directories($this->graphsDir) as $dir) {
            $name = basename($dir);
            if ($disk->exists($dir.'/base.json')) {
                $versions[] = $name;
            }
        }
        if ($versions === []) {
            return null;
        }
        usort($versions, 'version_compare');
        return end($versions);
    }

    /** Test/refresh hook: drop the in-process cache. */
    public static function clearCache(): void
    {
        self::$graphCache = [];
    }
}
