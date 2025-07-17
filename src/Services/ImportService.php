<?php

namespace Crumbls\Importer\Services;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Drivers\CsvDriver;
use Crumbls\Importer\Drivers\XmlDriver;
use Crumbls\Importer\Drivers\WpXmlDriver;
use Crumbls\Importer\Events\ImportServiceInitialized;
use Illuminate\Support\Manager;

class ImportService extends Manager
{
	private bool $initialized = false;

	public function driver($name = null)
	{
		$this->initialize();
		return parent::driver($name);
	}

    public function create($atts = []): ImportContract
    {
        $model = Import::class;
        $record = $model::create($atts);
        return $record;
    }

    public function getDefaultDriver()
    {
        return $this->config->get('importer.default_driver', AutoDriver::class);
    }

	public function initialize(): void 
	{
		if ($this->initialized) {
			return;
		}

		$this->initialized = true;
		
		ImportServiceInitialized::dispatch();
	}

	public function isInitialized(): bool 
	{
		return $this->initialized;
	}

    public function getAvailableDrivers(): array
    {
		$this->initialize();

        $drivers = array_keys($this->customCreators);



	    usort($drivers, function($a, $b) {
			$driverA = $this->driver($a);
            $driverB = $this->driver($b);
		    return $driverA::getPriority() <=> $driverB::getPriority();
        });

	    
        return $drivers;
    }
}