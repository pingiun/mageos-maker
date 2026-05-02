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
 * - Addons/layers that declare composer repositories: fetch
 *   `<url>/packages.json` (plus its `includes`), pick the highest stable
 *   version of each package.
 * - Addons whose `name` starts with `hyva` and have no declared repo:
 *   synthesise the Hyvä private composer repo from the configured
 *   license key (mageos.hyva_license_key, env MAGEOS_HYVA_LICENSE_KEY).
 * - Anything else: fall back to Packagist's p2 metadata endpoint.
 *
 * Results are persisted to `<cache_dir>/addon-versions.json` so the web
 * runtime can read them without network calls.
 */
class AddonVersionResolver
{
    private const PACKAGIST_P2 = 'https://repo.packagist.org/p2/%s.json';

    /** @var array<string,string>|null in-memory cache of the persisted map */
    private ?array $loaded = null;

    public function __construct(
        private readonly Definitions $defs,
        private readonly string $cacheDir,
        private readonly ?string $hyvaProject,
        private readonly ?string $hyvaLicenseKey,
    ) {}

    /**
     * Re-fetch the latest version for every addon/non-stock-layer package and
     * write the result to disk.
     *
     * @return array{versions: array<string,string>, warnings: list<string>}
     */
    public function refresh(): array
    {
        $warnings = [];
        $repoCache = [];
        $versions = [];

        foreach ($this->defs->addons as $name => $def) {
            $repos = $this->repositoriesForAddon($name, $def, $warnings);
            foreach ($def['packages'] ?? [] as $pkg) {
                $v = $this->latestStable($pkg, $repos, $repoCache, $warnings);
                if ($v !== null) {
                    $versions[$pkg] = $v;
                }
            }
        }

        foreach ($this->defs->layers as $def) {
            if (($def['stock'] ?? true) !== false) {
                continue;
            }
            $repos = $def['repositories'] ?? [];
            foreach ($def['packages'] ?? [] as $pkg) {
                $v = $this->latestStable($pkg, $repos, $repoCache, $warnings);
                if ($v !== null) {
                    $versions[$pkg] = $v;
                }
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
     * @param  list<array<string,mixed>>  $repos
     * @param  array<string,array<string,array<int,array<string,mixed>>>>  $cache  url → packages map
     * @param  list<string>  $warnings
     */
    private function latestStable(string $pkg, array $repos, array &$cache, array &$warnings): ?string
    {
        foreach ($repos as $repo) {
            if (($repo['type'] ?? null) !== 'composer') {
                continue;
            }
            $url = rtrim((string) ($repo['url'] ?? ''), '/');
            if ($url === '') {
                continue;
            }
            if (str_contains($url, 'repo.packagist.org')) {
                $v = $this->latestFromPackagistP2($pkg, $warnings);
                if ($v !== null) {
                    return $v;
                }

                continue;
            }
            $packages = $this->fetchComposerRepo($url, $cache, $warnings);
            $entries = $packages[$pkg] ?? null;
            if ($entries === null) {
                continue;
            }
            $v = $this->pickLatestStable($this->extractVersions($entries));
            if ($v !== null) {
                return $v;
            }
        }
        if ($repos === []) {
            return $this->latestFromPackagistP2($pkg, $warnings);
        }
        $warnings[] = "no version found for $pkg in declared repositories";

        return null;
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

        return $this->pickLatestStable($this->extractVersions($entries));
    }

    /**
     * Fetch a composer-type repo's packages.json and follow its `includes`.
     * `provider-includes` / `metadata-url` (Composer v2 lazy providers) are
     * not handled — we only need the eager listings used by Hyvä and Yireo.
     *
     * @param  array<string,array<string,array<int,array<string,mixed>>>>  $cache
     * @param  list<string>  $warnings
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function fetchComposerRepo(string $baseUrl, array &$cache, array &$warnings): array
    {
        if (isset($cache[$baseUrl])) {
            return $cache[$baseUrl];
        }
        $http = $this->httpForRepo($baseUrl);
        $manifestUrl = $baseUrl.'/packages.json';
        $resp = $http->get($manifestUrl);
        if (! $resp->successful()) {
            $warnings[] = "composer repo fetch failed: $manifestUrl (HTTP {$resp->status()})";

            return $cache[$baseUrl] = [];
        }
        $manifest = $resp->json();
        $packages = $manifest['packages'] ?? [];

        foreach ($manifest['includes'] ?? [] as $path => $_meta) {
            $r = $http->timeout(60)->get($baseUrl.'/'.ltrim((string) $path, '/'));
            if (! $r->successful()) {
                $warnings[] = "composer include fetch failed: $baseUrl/$path (HTTP {$r->status()})";

                continue;
            }
            $body = $r->json();
            $packages = array_merge($packages, $body['packages'] ?? []);
        }

        return $cache[$baseUrl] = $packages;
    }

    /**
     * Build a pre-configured HTTP client for a composer repo. The Hyvä private
     * Packagist needs HTTP basic auth — username "token", password = license
     * key — per https://docs.hyva.io getting-started.
     */
    private function httpForRepo(string $baseUrl)
    {
        $http = Http::timeout(30);
        if (str_contains($baseUrl, 'hyva-themes.repo.packagist.com')
            && $this->hyvaLicenseKey !== null && $this->hyvaLicenseKey !== '') {
            $http = $http->withBasicAuth('token', $this->hyvaLicenseKey);
        }

        return $http;
    }

    /**
     * @param  array<int|string,array<string,mixed>>  $entries
     * @return list<string>
     */
    private function extractVersions(array $entries): array
    {
        $versions = [];
        foreach ($entries as $key => $entry) {
            $v = is_array($entry) ? ($entry['version'] ?? (is_string($key) ? $key : null)) : null;
            if (is_string($v) && $v !== '') {
                $versions[] = $v;
            }
        }

        return $versions;
    }

    /** @param  list<string>  $versions */
    private function pickLatestStable(array $versions): ?string
    {
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
            // Unparseable mix — leave order as-is.
        }

        return $pool[0] ?? null;
    }

    /**
     * @param  array<string,mixed>  $def
     * @param  list<string>  $warnings
     * @return list<array<string,mixed>>
     */
    private function repositoriesForAddon(string $name, array $def, array &$warnings): array
    {
        $repos = $def['repositories'] ?? [];
        if ($repos === [] && str_starts_with($name, 'hyva')) {
            if ($this->hyvaProject === null || $this->hyvaProject === ''
                || $this->hyvaLicenseKey === null || $this->hyvaLicenseKey === '') {
                $warnings[] = "skipping $name: MAGEOS_HYVA_PROJECT and/or MAGEOS_HYVA_LICENSE_KEY not set";

                return [];
            }
            $repos[] = [
                'type' => 'composer',
                'url' => "https://hyva-themes.repo.packagist.com/{$this->hyvaProject}/",
            ];
        }

        return $repos;
    }
}
