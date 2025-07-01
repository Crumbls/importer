<?php

namespace Crumbls\Importer;

use Illuminate\Support\ServiceProvider;

class ImporterServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/importer.php' => config_path('importer.php'),
        ], 'config');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/importer.php', 'importer'
        );

        $this->app->singleton(ImporterManager::class, function ($app) {
            return new ImporterManager($app);
        });

        $this->app->alias(ImporterManager::class, 'importer');
    }
}
