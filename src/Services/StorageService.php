<?php

namespace Crumbls\Importer\Services;

use Crumbls\Importer\Events\StorageServiceInitialized;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Drivers\CsvDriver;
use Crumbls\Importer\Drivers\XmlDriver;
use Crumbls\Importer\Drivers\WpXmlDriver;
use Crumbls\Importer\Events\ImportServiceInitialized;
use Crumbls\Importer\StorageDrivers\Contracts\StorageDriverContract;
use Crumbls\Importer\StorageDrivers\SqliteDriver;
use Illuminate\Support\Manager;

class StorageService extends Manager
{
	private bool $initialized = false;

	public function driver($name = null) : StorageDriverContract
	{
		$this->initialize();
		return parent::driver($name);
	}


    public function getDefaultDriver() : string
    {
		$this->initialize();
        return $this->config->get('importer.default_storage', 'sqlite');
    }

	public function initialize(): void 
	{
		if ($this->initialized) {
			return;
		}

		$this->initialized = true;
		
		StorageServiceInitialized::dispatch();
	}

	public function isInitialized(): bool 
	{
		return $this->initialized;
	}

	protected function createSqliteDriver()
	{
		return new SqliteDriver();
	}

    public function getAvailableDrivers(): array
    {
		$this->initialize();

        // For now, just return the drivers we know about
        return ['sqlite'];
    }
}