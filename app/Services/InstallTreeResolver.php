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
        [$visited, $hits, $children] = $this->bfs($rootRequires, $packages, $disabled);

        $resultPackages = [];
        $byType = [];
        foreach ($visited as $name) {
            $node = $packages[$name] ?? null;
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

        $tree = $this->buildTree(array_values(array_filter(
            $rootRequires,
            fn ($r) => ! isset($disabled[$r]) && (isset($packages[$r]) || in_array($r, $visited, true)),
        )), $children, $packages);

        return [
            'version' => $base['version'],
            'count' => count($resultPackages),
            'missing' => $missing,
            'fallbackVersion' => $missing ? $fallbackVersion : null,
            'packages' => $resultPackages,
            'byType' => $byType,
            'disabledHits' => array_keys($hits),
            'tree' => $tree,
        ];
    }

    /**
     * Build a nested spanning tree from the BFS children map. Each node appears
     * exactly once, under its first discoverer (BFS parent). Shared deps are
     * marked with childCount on the node so the UI can show the duplication.
     *
     * @param  list<string>  $roots
     * @param  array<string,list<string>>  $children
     * @param  array<string,array{version?:string,type?:string,requires?:list<string>}>  $packages
     * @return list<array{name:string,version:string,type:string,sharedRefs:int,children:list<mixed>}>
     */
    private function buildTree(array $roots, array $children, array $packages): array
    {
        $sharedRefs = [];
        foreach ($packages as $name => $pkg) {
            foreach ($pkg['requires'] ?? [] as $dep) {
                $sharedRefs[$dep] = ($sharedRefs[$dep] ?? 0) + 1;
            }
        }

        $build = function (string $name) use (&$build, $children, $packages, $sharedRefs): array {
            $node = $packages[$name] ?? [];
            $kids = $children[$name] ?? [];
            sort($kids);
            return [
                'name' => $name,
                'version' => $node['version'] ?? '?',
                'type' => $node['type'] ?? 'library',
                'sharedRefs' => $sharedRefs[$name] ?? 0,
                'children' => array_map($build, $kids),
            ];
        };
        return array_map($build, $roots);
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
        $disabledSetMap = [];
        foreach ($sel->disabledSets as $set) {
            $disabledSetMap[$set] = true;
            foreach ($this->defs->setPackages($set) as $pkg) {
                $out[$pkg] = true;
            }
            // Parent set disabled → its subtoggles' packages are also out.
            foreach ($this->defs->setSubtoggles($set) as $sub) {
                foreach ($sub['packages'] ?? [] as $pkg) {
                    $out[$pkg] = true;
                }
            }
        }
        foreach ($sel->disabledSubtoggles as $key) {
            [$setName, $subName] = array_pad(explode('.', $key, 2), 2, '');
            if ($subName === '' || isset($disabledSetMap[$setName])) {
                continue;
            }
            foreach ($this->defs->subtogglePackages($setName, $subName) as $pkg) {
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
     * Returns [visitedInOrder, disabledHits, childrenSpanningTree].
     *
     * The spanning tree records, for each visited node, the deps it discovered
     * for the first time — i.e. the BFS-tree children. Shared dependencies
     * therefore appear under exactly one parent (the first one visited).
     *
     * @param  list<string>  $roots
     * @param  array<string,array{requires?:list<string>}>  $packages
     * @param  array<string,bool>  $disabled
     * @return array{0:list<string>,1:array<string,bool>,2:array<string,list<string>>}
     */
    private function bfs(array $roots, array $packages, array $disabled): array
    {
        $visited = [];
        $seen = [];
        $hits = [];
        $children = [];
        $queue = [];

        foreach ($roots as $r) {
            if (isset($seen[$r])) {
                continue;
            }
            $seen[$r] = true;
            if (isset($disabled[$r])) {
                $hits[$r] = true;
                continue;
            }
            $queue[] = $r;
        }

        for ($i = 0; $i < count($queue); $i++) {
            $name = $queue[$i];
            $visited[] = $name;
            $children[$name] = [];
            foreach ($packages[$name]['requires'] ?? [] as $dep) {
                if (isset($seen[$dep])) {
                    continue;
                }
                $seen[$dep] = true;
                if (isset($disabled[$dep])) {
                    $hits[$dep] = true;
                    continue;
                }
                $queue[] = $dep;
                $children[$name][] = $dep;
            }
        }
        return [$visited, $hits, $children];
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
