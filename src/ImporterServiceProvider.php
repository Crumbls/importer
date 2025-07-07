<?php

namespace Crumbls\Importer;

use Crumbls\Importer\Events\StorageServiceInitialized;
use Crumbls\Importer\Listeners\RegisterStorageDrivers;
use Crumbls\Importer\Services\ImportService;
use Crumbls\Importer\Console\ImporterCommand;
use Crumbls\Importer\Events\ImportServiceInitialized;
use Crumbls\Importer\Listeners\RegisterImportDrivers;
use Crumbls\Importer\Services\StorageService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

class ImporterServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/importer.php' => config_path('importer.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'migrations');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/importer'),
        ], 'lang');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'importer');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImporterCommand::class,
            ]);
        }

        // Register event listener for driver registration
        Event::listen(ImportServiceInitialized::class, RegisterImportDrivers::class);
		Event::listen(StorageServiceInitialized::class, RegisterStorageDrivers::class);
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/importer.php', 'importer'
        );

	    $this->app->singleton(ImportService::class, function ($app) {
		    return new ImportService($app);
	    });

	    $this->app->alias(ImportService::class, 'importer');


	    $this->app->singleton(StorageService::class, function ($app) {
		    return new StorageService($app);
	    });

	    $this->app->alias(StorageService::class, 'importer-storage');
    }
}
