<?php

namespace App\Services;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Pre-bakes the dependency graph for one Mage-OS version into a flat JSON file
 * (and per-option delta files). Walks package metadata directly:
 *   - mage-os/* packages come from the cached batch include — exact-pinned, no fetch.
 *   - third-party packages come from Packagist's p2 metadata-only endpoint, cached
 *     locally, with the highest stable version satisfying the constraint chosen.
 *
 * No `composer` subprocess. The plan permits this because the user asked for "what
 * packages get pulled in", not exact constraint solving — and mage-os pins its
 * subgraph anyway. See plans/02-install-tree.md.
 */
class GraphBaker
{
    private const PACKAGIST_P2 = 'https://repo.packagist.org/p2/%s.json';

    /** Resolved p2 metadata cache, per process. */
    private array $p2Cache = [];

    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly Definitions $defs,
        private readonly ComposerRepoIndex $repoIndex,
        private readonly string $editionPackage,
        private readonly string $graphsDir = 'graphs',
        private readonly string $packagistCacheDir = 'packagist-cache',
    ) {}

    /**
     * Bake (or re-bake) one version. Writes:
     *   graphs/<version>/base.json
     *   graphs/<version>/options/<group>/<option>.json   (one per non-default option that adds packages)
     *
     * @return array{baseWritten:bool, deltasWritten:list<string>, warnings:list<string>}
     */
    public function bake(string $version, bool $force = false): array
    {
        $disk = Storage::disk('local');
        $basePath = "$this->graphsDir/$version/base.json";
        $warnings = [];
        $deltasWritten = [];

        $editionDef = $this->catalog->packageVersions($this->editionPackage)[$version] ?? null;
        if ($editionDef === null) {
            throw new \RuntimeException("Edition package $this->editionPackage version $version not in catalog");
        }

        // Resolve from project-community-edition's requires, expanding mage-os/product-community-edition.
        $rootRequires = array_keys($this->stripPlatform($editionDef['require'] ?? []));
        $packages = [];
        foreach ($editionDef['require'] ?? [] as $name => $constraint) {
            if ($this->isPlatform($name)) {
                continue;
            }
            $this->collect($name, $constraint, $packages, $warnings);
        }

        $baseGraph = [
            'version' => $version,
            'rootRequires' => array_values($rootRequires),
            'packages' => $this->serializePackages($packages),
        ];

        $baseChanged = $force || ! $disk->exists($basePath)
            || $disk->get($basePath) !== json_encode($baseGraph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($baseChanged) {
            $this->atomicWrite($basePath, $baseGraph);
        }

        // Bake non-default profile-group option deltas that add packages via add-ons.
        foreach ($this->defs->profileGroups as $groupName => $group) {
            $defaultOpt = $this->defs->defaultProfileGroupOption($groupName);
            foreach ($group['options'] ?? [] as $opt) {
                if ($opt['name'] === $defaultOpt) {
                    continue;
                }
                $extraPackages = $this->extraPackagesForOption($opt);
                if ($extraPackages === []) {
                    continue;
                }

                $delta = $this->bakeDelta($version, ['group' => $groupName, 'option' => $opt['name']], $extraPackages, $packages, $warnings);
                $deltaPath = "$this->graphsDir/$version/options/$groupName/{$opt['name']}.json";
                $deltaChanged = $force || ! $disk->exists($deltaPath)
                    || $disk->get($deltaPath) !== json_encode($delta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($deltaChanged) {
                    $this->atomicWrite($deltaPath, $delta);
                    $deltasWritten[] = "$groupName/{$opt['name']}";
                }
            }
        }

        // Bake per-addon deltas so the install-tree resolver can show packages
        // contributed by add-ons toggled directly in the Add-ons panel — those
        // aren't tied to any profile-group option.
        foreach (array_keys($this->defs->addons) as $addonName) {
            $extraPackages = $this->defs->addonPackages($addonName);
            if ($extraPackages === []) {
                continue;
            }
            $delta = $this->bakeDelta($version, ['kind' => 'addon', 'name' => $addonName], $extraPackages, $packages, $warnings);
            $deltaPath = "$this->graphsDir/$version/addons/$addonName.json";
            $deltaChanged = $force || ! $disk->exists($deltaPath)
                || $disk->get($deltaPath) !== json_encode($delta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($deltaChanged) {
                $this->atomicWrite($deltaPath, $delta);
                $deltasWritten[] = "addons/$addonName";
            }
        }

        // Same idea for non-stock layers toggled directly.
        foreach ($this->defs->layers as $layerName => $layerDef) {
            if (($layerDef['stock'] ?? true) !== false) {
                continue;
            }
            $extraPackages = $this->defs->layerPackages($layerName);
            if ($extraPackages === []) {
                continue;
            }
            $delta = $this->bakeDelta($version, ['kind' => 'layer', 'name' => $layerName], $extraPackages, $packages, $warnings);
            $deltaPath = "$this->graphsDir/$version/layers/$layerName.json";
            $deltaChanged = $force || ! $disk->exists($deltaPath)
                || $disk->get($deltaPath) !== json_encode($delta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($deltaChanged) {
                $this->atomicWrite($deltaPath, $delta);
                $deltasWritten[] = "layers/$layerName";
            }
        }

        InstallTreeResolver::clearCache();

        return ['baseWritten' => $baseChanged, 'deltasWritten' => $deltasWritten, 'warnings' => $warnings];
    }

    /**
     * @param  array<string,string>  $appliesTo  arbitrary marker for which selection knob this delta applies to
     *                                          — e.g. {group, option} for profile-group options,
     *                                          {kind: 'addon'|'layer', name} for direct toggles.
     * @param  list<string>  $extraPackages
     * @param  array<string,array{version:string,type:string,requires:array<string,string>,replaces:array<string,string>}>  $basePackages
     */
    private function bakeDelta(string $version, array $appliesTo, array $extraPackages, array $basePackages, array &$warnings): array
    {
        $addPackages = [];
        $addRequires = $extraPackages;

        foreach ($extraPackages as $name) {
            $this->collect($name, '*', $addPackages, $warnings);
        }

        // Subtract anything already in the base.
        $deltaPackages = [];
        foreach ($addPackages as $name => $pkg) {
            if (isset($basePackages[$name]) && ($basePackages[$name]['version'] ?? null) === ($pkg['version'] ?? null)) {
                continue;
            }
            $deltaPackages[$name] = $pkg;
        }

        return [
            'version' => $version,
            'appliesTo' => $appliesTo,
            'addRequires' => array_values($addRequires),
            'addPackages' => $this->serializePackages($deltaPackages),
        ];
    }

    /**
     * For a profile-group option, collect the extra package names introduced by its
     * forced+enabled add-ons and (non-stock) layers.
     *
     * @return list<string>
     */
    private function extraPackagesForOption(array $opt): array
    {
        $names = [];
        foreach (($opt['forces']['addons'] ?? []) as $addon) {
            foreach ($this->defs->addonPackages($addon) as $pkg) {
                $names[] = $pkg;
            }
        }
        foreach (($opt['enables']['addons'] ?? []) as $addon) {
            foreach ($this->defs->addonPackages($addon) as $pkg) {
                $names[] = $pkg;
            }
        }
        foreach (($opt['forces']['layers'] ?? []) as $layer) {
            if ($this->defs->isLayerStock($layer)) {
                continue;
            }
            foreach ($this->defs->layerPackages($layer) as $pkg) {
                $names[] = $pkg;
            }
        }
        foreach (($opt['enables']['layers'] ?? []) as $layer) {
            if ($this->defs->isLayerStock($layer)) {
                continue;
            }
            foreach ($this->defs->layerPackages($layer) as $pkg) {
                $names[] = $pkg;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Recursive walk: resolve $name@$constraint, add to $out, recurse on its requires.
     *
     * @param  array<string,array{version:string,type:string,requires:array<string,string>,replaces:array<string,string>}>  $out
     */
    private function collect(string $name, string $constraint, array &$out, array &$warnings): void
    {
        if ($this->isPlatform($name)) {
            return;
        }
        if (isset($out[$name])) {
            return;
        }
        $def = $this->resolve($name, $constraint, $warnings);
        if ($def === null) {
            $warnings[] = "missing package metadata: $name (constraint: $constraint)";

            return;
        }
        $out[$name] = [
            'version' => $def['version'] ?? '?',
            'type' => $def['type'] ?? 'library',
            'requires' => $this->stripPlatform($def['require'] ?? []),
            'replaces' => $def['replace'] ?? [],
        ];
        foreach ($out[$name]['requires'] as $depName => $depConstraint) {
            $this->collect($depName, $depConstraint, $out, $warnings);
        }
    }

    /**
     * Pick a concrete version definition for $name satisfying $constraint.
     * - mage-os/* → from cached include file.
     * - everything else → from packagist p2 endpoint.
     *
     * Returns the package definition array (with 'version', 'require', 'type', ...) or null.
     */
    private function resolve(string $name, string $constraint, array &$warnings): ?array
    {
        $versions = $this->versionsOf($name);
        if ($versions === []) {
            return null;
        }
        $candidates = array_keys($versions);

        $parser = new VersionParser;
        $satisfying = [];
        foreach ($candidates as $v) {
            try {
                if (Semver::satisfies($v, $constraint === '' ? '*' : $constraint)) {
                    $satisfying[] = $v;
                }
            } catch (\Throwable) {
                // Ignore unparseable version strings (dev-* branches etc.).
            }
        }
        if ($satisfying === []) {
            // Fall back to highest stable available so visualization still shows something.
            $satisfying = $candidates;
            $warnings[] = "no version of $name satisfies $constraint; using highest known";
        }
        // Prefer stable; among stable pick highest. Composer's parseStability handles dev/alpha/beta/RC.
        $stable = array_filter($satisfying, fn ($v) => VersionParser::parseStability($v) === 'stable');
        $pool = $stable !== [] ? $stable : $satisfying;
        usort($pool, fn ($a, $b) => $parser->normalize($a) <=> $parser->normalize($b) ?: strcmp($a, $b));
        // The above lexical compare on normalized form is wrong for proper semver — use Semver::sort
        $pool = Semver::rsort($pool);
        $chosen = $pool[0];

        return $versions[$chosen];
    }

    /** All known version definitions for a package, keyed by version string. */
    private function versionsOf(string $name): array
    {
        $fromCatalog = $this->catalog->packageVersions($name);
        if ($fromCatalog !== []) {
            return $fromCatalog;
        }
        // Eager composer-repo aggregator (Hyvä private repo, addon-declared
        // composer repos). Populated by mageos:catalog:update — without that
        // refresh, the index is empty and we drop straight to packagist.
        $fromIndex = $this->repoIndex->packageVersions($name);
        if ($fromIndex !== []) {
            return $fromIndex;
        }

        return $this->fetchPackagist($name);
    }

    private function fetchPackagist(string $name): array
    {
        if (isset($this->p2Cache[$name])) {
            return $this->p2Cache[$name];
        }

        $disk = Storage::disk('local');
        $cachePath = "$this->packagistCacheDir/".str_replace('/', '__', $name).'.json';
        $body = null;
        if ($disk->exists($cachePath)) {
            $body = $disk->get($cachePath);
        } else {
            $url = sprintf(self::PACKAGIST_P2, $name);
            $resp = Http::timeout(20)->get($url);
            if ($resp->status() === 404) {
                return $this->p2Cache[$name] = [];
            }
            if (! $resp->successful()) {
                return $this->p2Cache[$name] = [];
            }
            $body = $resp->body();
            $disk->put($cachePath, $body);
        }

        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $entries = $data['packages'][$name] ?? [];
        // p2 returns a list of version objects; key by version string.
        $byVersion = [];
        foreach ($entries as $entry) {
            $v = $entry['version'] ?? null;
            if ($v === null) {
                continue;
            }
            $byVersion[$v] = $entry;
        }

        return $this->p2Cache[$name] = $byVersion;
    }

    private function isPlatform(string $name): bool
    {
        return $name === 'php'
            || str_starts_with($name, 'php-')
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-')
            || $name === 'composer-plugin-api'
            || $name === 'composer-runtime-api';
    }

    private function stripPlatform(array $requires): array
    {
        $out = [];
        foreach ($requires as $name => $constraint) {
            if ($this->isPlatform($name)) {
                continue;
            }
            $out[$name] = $constraint;
        }

        return $out;
    }

    /**
     * @param  array<string,array{version:string,type:string,requires:array<string,string>,replaces:array<string,string>}>  $packages
     */
    private function serializePackages(array $packages): array
    {
        $out = [];
        ksort($packages);
        foreach ($packages as $name => $pkg) {
            $out[$name] = [
                'version' => $pkg['version'],
                'type' => $pkg['type'],
                'requires' => array_values(array_keys($pkg['requires'])),
                'replaces' => array_values(array_keys($pkg['replaces'] ?? [])),
            ];
        }

        return $out;
    }

    private function atomicWrite(string $path, array $data): void
    {
        $disk = Storage::disk('local');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $path.'.tmp';
        $disk->put($tmp, $json);
        // Storage abstraction doesn't expose rename; do it on the underlying path.
        $absTmp = $disk->path($tmp);
        $absDest = $disk->path($path);
        @mkdir(dirname($absDest), 0775, true);
        if (! @rename($absTmp, $absDest)) {
            // Fall back to copy + delete if rename across filesystems fails.
            $disk->put($path, $json);
            $disk->delete($tmp);
        }
    }
}
