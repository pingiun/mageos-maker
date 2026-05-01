<?php

namespace App\Services;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Reads (and refreshes) the cached Mage-OS Composer manifest.
 *
 * Cache layout (under storage/app/<cache_dir>/):
 *   manifest.json   — packages.json verbatim
 *   manifest.etag   — last seen ETag (for conditional GET)
 *   include-<hash>.json — referenced batch include
 */
class CatalogRepository
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly string $catalogUrl,
        private readonly string $editionPackage,
        private readonly string $fallbackVersion,
    ) {}

    /** Refresh manifest + include from upstream. Returns true if anything changed. */
    public function refresh(): bool
    {
        $headers = [];
        $etag = $this->readFile('manifest.etag');
        if ($etag !== null) {
            $headers['If-None-Match'] = trim($etag);
        }

        $response = Http::withHeaders($headers)->get($this->catalogUrl);

        if ($response->status() === 304) {
            return false;
        }
        $response->throw();

        $this->writeFile('manifest.json', $response->body());
        if ($response->header('ETag')) {
            $this->writeFile('manifest.etag', $response->header('ETag'));
        }

        $manifest = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($manifest['includes'] ?? [] as $path => $_meta) {
            $local = $this->includeLocalName($path);
            if ($this->fileExists($local)) {
                continue;
            }
            $url = rtrim(dirname($this->catalogUrl), '/').'/'.ltrim($path, '/');
            $body = Http::timeout(180)->get($url)->throw()->body();
            $this->writeFile($local, $body);
        }

        return true;
    }

    /** All available versions of the edition package, semver-sorted ascending. */
    public function availableVersions(): array
    {
        $defs = $this->packageVersions($this->editionPackage);
        $versions = array_keys($defs);

        $parser = new VersionParser();
        usort($versions, function (string $a, string $b) use ($parser) {
            return Comparator::compare($parser->normalize($a), '<', $parser->normalize($b)) ? -1 : 1;
        });

        return $versions;
    }

    /** Highest stable version, or fallback if cache is empty. */
    public function latestStable(): string
    {
        $versions = $this->availableVersions();
        if ($versions === []) {
            return $this->fallbackVersion;
        }

        $stable = array_filter($versions, fn ($v) => VersionParser::parseStability($v) === 'stable');
        if ($stable === []) {
            return end($versions);
        }
        return end($stable);
    }

    /** Raw package definitions for one package, keyed by version string. */
    public function packageVersions(string $name): array
    {
        $packages = $this->allPackages();
        return $packages[$name] ?? [];
    }

    /** All packages from the bundled include file, keyed by package name then version. */
    public function allPackages(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $manifest = $this->readJson('manifest.json');
        if ($manifest === null) {
            return $cache = [];
        }

        $packages = $manifest['packages'] ?? [];
        foreach (array_keys($manifest['includes'] ?? []) as $path) {
            $local = $this->includeLocalName($path);
            $included = $this->readJson($local);
            if ($included !== null && isset($included['packages'])) {
                $packages = array_merge($packages, $included['packages']);
            }
        }

        return $cache = $packages;
    }

    private function includeLocalName(string $path): string
    {
        return 'include-'.md5($path).'.json';
    }

    private function readFile(string $name): ?string
    {
        $disk = Storage::disk('local');
        $full = $this->cacheDir.'/'.$name;
        return $disk->exists($full) ? $disk->get($full) : null;
    }

    private function writeFile(string $name, string $contents): void
    {
        Storage::disk('local')->put($this->cacheDir.'/'.$name, $contents);
    }

    private function fileExists(string $name): bool
    {
        return Storage::disk('local')->exists($this->cacheDir.'/'.$name);
    }

    private function readJson(string $name): ?array
    {
        $raw = $this->readFile($name);
        if ($raw === null) {
            return null;
        }
        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
