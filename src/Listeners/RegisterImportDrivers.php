<?php

namespace Crumbls\Importer\Listeners;

use Crumbls\Importer\Events\ImportServiceInitialized;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Drivers\CsvDriver;
use Crumbls\Importer\Drivers\XmlDriver;
use Crumbls\Importer\Drivers\WpXmlDriver;
use Crumbls\Importer\Models\Contracts\ImportContract;

class RegisterImportDrivers
{
    public function handle(ImportServiceInitialized $event): void
    {
	    Importer::extend('auto', function($app) {
            // Return the class name instead of instantiating
            return AutoDriver::class;
        });

	    Importer::extend('csv', function($app) {
            return CsvDriver::class;
        });

	    Importer::extend('wpxml', function($app) {
            return WpXmlDriver::class;
	    });

        Importer::extend('xml', function($app) {
            return XmlDriver::class;
        });
    }
}