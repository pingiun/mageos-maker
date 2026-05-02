<?php

namespace App\Providers;

use App\Services\AddonVersionResolver;
use App\Services\CatalogRepository;
use App\Services\ComposerRepoIndex;
use App\Services\Configurator;
use App\Services\DefinitionLoader;
use App\Services\Definitions;
use App\Services\GraphBaker;
use App\Services\InstallTreeResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CatalogRepository::class, function ($app) {
            $config = $app['config']->get('mageos');

            return new CatalogRepository(
                cacheDir: $config['cache_dir'],
                catalogUrl: $config['catalog_url'],
                editionPackage: $config['edition_package'],
                fallbackVersion: $config['fallback_version'],
            );
        });

        $this->app->singleton(DefinitionLoader::class, function ($app) {
            $path = base_path($app['config']->get('mageos.definitions_path'));

            return new DefinitionLoader($path);
        });

        $this->app->singleton(Definitions::class, fn ($app) => $app->make(DefinitionLoader::class)->load());

        $this->app->singleton(ComposerRepoIndex::class, function ($app) {
            $config = $app['config']->get('mageos');
            $defs = $app->make(Definitions::class);
            $repos = [];
            $seen = [];

            // Hyvä's private packagist needs HTTP basic auth (token + license key).
            // Synthesised from env, since the YAML can't carry secrets.
            $hyvaProject = $config['hyva_project'] ?? null;
            $hyvaKey = $config['hyva_license_key'] ?? null;
            if ($hyvaProject && $hyvaKey) {
                $url = "https://hyva-themes.repo.packagist.com/{$hyvaProject}";
                $repos[] = ['url' => $url, 'basicAuth' => ['token', $hyvaKey]];
                $seen[$url] = true;
            }

            $collect = function (array $declared) use (&$repos, &$seen) {
                foreach ($declared as $repo) {
                    if (($repo['type'] ?? null) !== 'composer' || empty($repo['url'])) {
                        continue;
                    }
                    $url = rtrim((string) $repo['url'], '/');
                    // Packagist itself is lazy-provider; the eager fetcher would
                    // 404 on packages.json. Per-package p2 lookup handles it.
                    if (str_contains($url, 'repo.packagist.org')) {
                        continue;
                    }
                    if (isset($seen[$url])) {
                        continue;
                    }
                    $repos[] = ['url' => $url];
                    $seen[$url] = true;
                }
            };
            foreach ($defs->addons as $a) {
                $collect($a['repositories'] ?? []);
            }
            foreach ($defs->layers as $l) {
                $collect($l['repositories'] ?? []);
            }

            return new ComposerRepoIndex($repos, $config['cache_dir']);
        });

        $this->app->singleton(AddonVersionResolver::class, fn ($app) => new AddonVersionResolver(
            $app->make(Definitions::class),
            $app->make(ComposerRepoIndex::class),
            $app['config']->get('mageos.cache_dir'),
        ));

        $this->app->singleton(Configurator::class, fn ($app) => new Configurator(
            $app->make(Definitions::class),
            $app->make(CatalogRepository::class),
            $app->make(AddonVersionResolver::class),
            $app['config']->get('mageos.repository_url'),
        ));

        $this->app->singleton(InstallTreeResolver::class, fn ($app) => new InstallTreeResolver(
            $app->make(Definitions::class),
            $app['config']->get('mageos.graphs_dir', 'graphs'),
        ));

        $this->app->singleton(GraphBaker::class, fn ($app) => new GraphBaker(
            $app->make(CatalogRepository::class),
            $app->make(Definitions::class),
            $app->make(ComposerRepoIndex::class),
            $app['config']->get('mageos.edition_package'),
            $app['config']->get('mageos.graphs_dir', 'graphs'),
            $app['config']->get('mageos.packagist_cache_dir', 'packagist-cache'),
        ));
    }

    public function boot(): void {}
}
