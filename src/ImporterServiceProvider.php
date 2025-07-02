<?php

namespace Crumbls\Importer;

use Illuminate\Support\ServiceProvider;
use Crumbls\Importer\Console\ImportCommand;
use Crumbls\Importer\Console\ImportStatusCommand;

class ImporterServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/importer.php' => config_path('importer.php'),
        ], 'config');
        
        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportCommand::class,
                ImportStatusCommand::class,
            ]);
        }
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
