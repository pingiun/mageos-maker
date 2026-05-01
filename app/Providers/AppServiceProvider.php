<?php

namespace App\Providers;

use App\Services\CatalogRepository;
use App\Services\Configurator;
use App\Services\Definitions;
use App\Services\DefinitionLoader;
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

        $this->app->singleton(Configurator::class, fn ($app) => new Configurator(
            $app->make(Definitions::class),
            $app->make(CatalogRepository::class),
            $app['config']->get('mageos.repository_url'),
        ));
    }

    public function boot(): void {}
}
