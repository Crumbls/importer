<?php

namespace Crumbls\Importer;


use Crumbls\Importer\Console\Commands\TestImportCommand;
use Crumbls\Importer\Support\ImportManager;
use Illuminate\Database\Eloquent;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class ImporterServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void {

	    $this->mergeConfigFrom(
		    __DIR__.'/../config/config.php', 'importer'
	    );

	    $this->app->singleton('importer', function ($app) {
		    return new ImportManager($app);
	    });

	    $this->app->alias('importer', ImportManager::class);


    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
	    if ($this->app->runningInConsole()) {
		    $this->publishes([
			    __DIR__.'/../config/config.php' => config_path('config.php'),
		    ], 'importer-config');

		    $this->publishes([
			    __DIR__.'/../database/migrations/' => database_path('migrations'),
		    ], 'importer-migrations');
	    }

	    $this->bootCommands();
    }

	/**
	 * Bring our routes online.
	 */
	private function bootCommands() : void {
		if (!$this->app->runningInConsole()) {
			return;
		}
		$this->commands([
			TestImportCommand::class
//			GenerateImporterCommand::class,
//			ImportCommand::class
		]);
	}

}
