<?php

namespace Crumbls\Importer\Listeners;

use Crumbls\Importer\Events\ImportServiceInitialized;
use Crumbls\Importer\Events\StorageServiceInitialized;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Drivers\CsvDriver;
use Crumbls\Importer\Drivers\XmlDriver;
use Crumbls\Importer\Drivers\WpXmlDriver;
use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Services\StorageService;
use Crumbls\Importer\StorageDrivers\SqliteDriver;

class RegisterStorageDrivers
{
    public function handle(StorageServiceInitialized $event): void
    {
	    Storage::extend('sqlite', function($app) {
			return $app->make(SqliteDriver::class);
        });
    }
}