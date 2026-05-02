<?php

namespace App\Services;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Looks up the latest stable version of every package referenced by an
 * add-on or non-stock layer, so the generated composer.json can pin
 * those packages instead of leaving them at "*".
 *
 * Resolution order per package:
 *   1. {@see ComposerRepoIndex} (Hyvä private repo + any composer-type
 *      addon repos declared in the YAML)
 *   2. Packagist's per-package p2 metadata endpoint (for public packages
 *      not on a custom repo)
 *
 * Results are persisted to `<cache_dir>/addon-versions.json` so the
 * web runtime can read them without network calls.
 */
class AddonVersionResolver
{
    private const PACKAGIST_P2 = 'https://repo.packagist.org/p2/%s.json';

    /** @var array<string,string>|null in-memory cache of the persisted map */
    private ?array $loaded = null;

    public function __construct(
        private readonly Definitions $defs,
        private readonly ComposerRepoIndex $repoIndex,
        private readonly string $cacheDir,
    ) {}

    /**
     * Re-fetch the latest version for every addon/non-stock-layer package and
     * write the result to disk. Assumes {@see ComposerRepoIndex::refresh()}
     * has already been called this run (the orchestrating command does that).
     *
     * @return array{versions: array<string,string>, warnings: list<string>}
     */
    public function refresh(): array
    {
        $warnings = [];
        $versions = [];

        foreach ($this->collectGatedPackages() as $pkg) {
            $v = $this->repoIndex->latestStable($pkg)
                ?? $this->latestFromPackagistP2($pkg, $warnings);
            if ($v !== null) {
                $versions[$pkg] = $v;
            } else {
                $warnings[] = "no version resolved for $pkg";
            }
        }

        ksort($versions);
        Storage::disk('local')->put(
            $this->cacheDir.'/addon-versions.json',
            json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        $this->loaded = $versions;

        return ['versions' => $versions, 'warnings' => $warnings];
    }

    /** Locked composer constraint for a package, or null if unknown. */
    public function constraint(string $package): ?string
    {
        $map = $this->all();

        return $map[$package] ?? null;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $disk = Storage::disk('local');
        $path = $this->cacheDir.'/addon-versions.json';
        if (! $disk->exists($path)) {
            return $this->loaded = [];
        }
        $raw = $disk->get($path);
        $decoded = json_decode($raw, true);

        return $this->loaded = is_array($decoded) ? $decoded : [];
    }

    /**
     * All package names declared by any addon or non-stock layer, regardless
     * of `requires:` gates — pinning the latest version is harmless even when
     * the package only ever ships under a specific selection.
     *
     * @return list<string>
     */
    private function collectGatedPackages(): array
    {
        $names = [];
        foreach (array_keys($this->defs->addons) as $addon) {
            foreach ($this->defs->addonPackages($addon) as $pkg) {
                $names[$pkg] = true;
            }
        }
        foreach ($this->defs->layers as $name => $def) {
            if (($def['stock'] ?? true) !== false) {
                continue;
            }
            foreach ($this->defs->layerPackages($name) as $pkg) {
                $names[$pkg] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function latestFromPackagistP2(string $pkg, array &$warnings): ?string
    {
        $resp = Http::timeout(20)->get(sprintf(self::PACKAGIST_P2, $pkg));
        if (! $resp->successful()) {
            $warnings[] = "packagist p2 fetch failed for $pkg (HTTP {$resp->status()})";

            return null;
        }
        $data = $resp->json();
        $entries = $data['packages'][$pkg] ?? [];
        $versions = [];
        foreach ($entries as $e) {
            if (isset($e['version']) && is_string($e['version'])) {
                $versions[] = $e['version'];
            }
        }
        if ($versions === []) {
            return null;
        }
        $stable = array_filter($versions, function (string $v): bool {
            try {
                return VersionParser::parseStability($v) === 'stable';
            } catch (\Throwable) {
                return false;
            }
        });
        $pool = $stable !== [] ? $stable : $versions;
        try {
            $pool = Semver::rsort($pool);
        } catch (\Throwable) {
        }

        return $pool[0] ?? null;
    }
}
