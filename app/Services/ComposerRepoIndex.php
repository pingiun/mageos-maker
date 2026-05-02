<?php

namespace App\Services;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Aggregator over a list of "eager" composer repositories — those that
 * expose a `packages.json` (optionally with `includes`) listing every
 * package and version. Hyvä's private Packagist and yireo.com both
 * publish in this format. Lazy-provider repos (Packagist itself, with
 * `metadata-url` / `provider-includes`) are out of scope here; callers
 * fall back to per-package fetches for those.
 *
 * The merged map is persisted to `<cacheDir>/composer-repos.json` by
 * `mageos:catalog:update` and read back at runtime by
 * {@see AddonVersionResolver} (to pin add-on versions) and
 * {@see GraphBaker} (to walk the install tree of add-on packages).
 */
class ComposerRepoIndex
{
    /** @var array<string, array<string, array<string, mixed>>>|null in-memory cache of the persisted map */
    private ?array $loaded = null;

    /**
     * @param  list<array{url:string, basicAuth?:array{0:string,1:string}}>  $repos
     */
    public function __construct(
        private readonly array $repos,
        private readonly string $cacheDir,
    ) {}

    /**
     * Re-download every configured repo and write the merged package map.
     *
     * @return array{packageCount:int, warnings:list<string>}
     */
    public function refresh(): array
    {
        $warnings = [];
        $merged = [];
        foreach ($this->repos as $repo) {
            $packages = $this->fetchRepo($repo, $warnings);
            foreach ($packages as $name => $entries) {
                $merged[$name] = ($merged[$name] ?? []) + $this->normalizeVersions($entries);
            }
        }
        ksort($merged);
        Storage::disk('local')->put(
            $this->cacheDir.'/composer-repos.json',
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        $this->loaded = $merged;

        return ['packageCount' => count($merged), 'warnings' => $warnings];
    }

    /**
     * Versions of one package across all configured repos.
     *
     * @return array<string, array<string, mixed>> [version => packageDef]
     */
    public function packageVersions(string $name): array
    {
        return $this->load()[$name] ?? [];
    }

    /** Highest stable version across configured repos, or null if unknown. */
    public function latestStable(string $name): ?string
    {
        $versions = array_keys($this->packageVersions($name));
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
            // Mixed/unparseable versions — leave order alone.
        }

        return $pool[0] ?? null;
    }

    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $disk = Storage::disk('local');
        $path = $this->cacheDir.'/composer-repos.json';
        if (! $disk->exists($path)) {
            return $this->loaded = [];
        }
        $decoded = json_decode($disk->get($path), true);

        return $this->loaded = is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array{url:string, basicAuth?:array{0:string,1:string}}  $repo
     * @param  list<string>  $warnings
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function fetchRepo(array $repo, array &$warnings): array
    {
        $baseUrl = rtrim($repo['url'], '/');
        $http = Http::timeout(60);
        if (isset($repo['basicAuth'])) {
            [$u, $p] = $repo['basicAuth'];
            $http = $http->withBasicAuth($u, $p);
        }
        $resp = $http->get($baseUrl.'/packages.json');
        if (! $resp->successful()) {
            $warnings[] = "composer repo fetch failed: $baseUrl/packages.json (HTTP {$resp->status()})";

            return [];
        }
        $manifest = $resp->json();
        $packages = $manifest['packages'] ?? [];
        foreach ($manifest['includes'] ?? [] as $path => $_meta) {
            $r = $http->get($baseUrl.'/'.ltrim((string) $path, '/'));
            if (! $r->successful()) {
                $warnings[] = "composer include fetch failed: $baseUrl/$path (HTTP {$r->status()})";

                continue;
            }
            $body = $r->json();
            $packages = array_merge($packages, $body['packages'] ?? []);
        }

        return $packages;
    }

    /**
     * Composer repos can list versions either as a list (with `version` on
     * each entry) or as a `[versionString => def]` map. Normalise to the
     * map form, dropping entries with no usable version string.
     *
     * @param  array<int|string, array<string, mixed>>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function normalizeVersions(array $entries): array
    {
        $out = [];
        foreach ($entries as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $v = $entry['version'] ?? (is_string($key) ? $key : null);
            if (! is_string($v) || $v === '') {
                continue;
            }
            $out[$v] = isset($entry['version']) ? $entry : ['version' => $v] + $entry;
        }

        return $out;
    }
}
