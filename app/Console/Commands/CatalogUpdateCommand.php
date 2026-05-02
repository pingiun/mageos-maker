<?php

namespace App\Console\Commands;

use App\Services\CatalogRepository;
use App\Services\GraphBaker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CatalogUpdateCommand extends Command
{
    protected $signature = 'mageos:catalog:update {--bake-only : Skip the manifest fetch and only run the graph baker}
                                                  {--force : Re-bake graphs even when up to date}
                                                  {--only=* : Only bake these version strings (defaults to all available)}';

    protected $description = 'Refresh the cached Mage-OS Composer manifest and pre-bake install-tree graphs';

    public function handle(CatalogRepository $catalog, GraphBaker $baker): int
    {
        if (! $this->option('bake-only')) {
            $changed = $catalog->refresh();
            $this->info($changed ? 'Catalog updated.' : 'Catalog already up to date (304).');
        }

        $latest = $catalog->latestStable();
        $allVersions = $catalog->availableVersions();
        $count = count($allVersions);
        $this->line("Latest stable: {$latest}  ({$count} versions known)");

        $force = (bool) $this->option('force');
        $only = $this->option('only');
        $targets = $only !== [] ? array_values(array_intersect($allVersions, $only)) : $allVersions;

        $disk = Storage::disk('local');
        $graphsDir = config('mageos.graphs_dir', 'graphs');
        $bakedAny = false;

        foreach ($targets as $version) {
            $basePath = "$graphsDir/$version/base.json";
            if (! $force && $disk->exists($basePath)) {
                continue;
            }
            $this->line("Baking graph for $version ...");
            try {
                $result = $baker->bake($version, force: $force);
            } catch (\Throwable $e) {
                $this->warn("  failed: {$e->getMessage()}");
                continue;
            }
            $bakedAny = true;
            $this->info('  base: '.($result['baseWritten'] ? 'written' : 'unchanged').
                '  deltas: '.(count($result['deltasWritten']) === 0 ? 'none' : implode(', ', $result['deltasWritten'])));
            foreach ($result['warnings'] as $w) {
                $this->warn("  warn: $w");
            }
        }

        if (! $bakedAny) {
            $this->line('All graphs up to date.');
        }

        return self::SUCCESS;
    }
}
