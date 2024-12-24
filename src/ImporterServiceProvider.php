<?php

namespace Crumbls\Importer;


use Crumbls\Importer\Console\Commands\TestImportCommand;
use Crumbls\Importer\Support\ImportManager;
use Crumbls\Importer\Transformers\Categories\ArrayTransformer;
use Crumbls\Importer\Transformers\Categories\DateTransformer;
use Crumbls\Importer\Transformers\Categories\NumberTransformer;
use Crumbls\Importer\Transformers\Categories\StringTransformer;
use Crumbls\Importer\Transformers\TransformerRegistry;
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


	    $this->app->singleton(TransformerRegistry::class, function() {
		    $registry = new TransformerRegistry();

		    // Register default transformers
		    $registry->register(new StringTransformer());
		    $registry->register(new DateTransformer());
			$registry->register(new NumberTransformer());
		    $registry->register(new ArrayTransformer());

		    return $registry;
	    });
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
