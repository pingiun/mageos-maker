<?php

namespace App\Console\Commands;

use App\Services\CatalogRepository;
use Illuminate\Console\Command;

class CatalogUpdateCommand extends Command
{
    protected $signature = 'mageos:catalog:update';

    protected $description = 'Refresh the cached Mage-OS Composer manifest from repo.mage-os.org';

    public function handle(CatalogRepository $catalog): int
    {
        $changed = $catalog->refresh();
        $this->info($changed ? 'Catalog updated.' : 'Catalog already up to date (304).');

        $latest = $catalog->latestStable();
        $count = count($catalog->availableVersions());
        $this->line("Latest stable: {$latest}  ({$count} versions known)");

        return self::SUCCESS;
    }
}
