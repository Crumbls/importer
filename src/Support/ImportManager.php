<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Drivers\WordPressXML\WordPressXmlDriver;
use Illuminate\Support\Manager;
use Crumbls\Importer\Contracts\DriverInterface;
use Crumbls\Importer\Tasks\ImportTask;

class ImportManager extends Manager
{
	/**
	 * Get the default driver name.
	 */
	public function getDefaultDriver(): string
	{
		return $this->config->get('importer.default', WordPressXmlDriver::getName());
	}

	/**
	 * Create an import task.
	 */
	public function create(string $driver = null): ImportTask
	{
		return new ImportTask($this->driver($driver));
	}

	/**
	 * Create a WordPress XML driver instance.
	 */
	protected function createWordPressXmlDriver(): WordPressXmlDriver
	{
		$config = $this->config->get('importer.drivers.wordpress-xml', []);
		dump('Creating driver with config:', $config);
		return new WordPressXmlDriver($config);
	}

	public function driver($driver = null)
	{
		dump('Requested driver:', $driver);
		return parent::driver($driver);
	}

	/**
	 * Get available drivers
	 * TODO: Make this scan all drivers for their names.
	 */
	public function getAvailableDrivers(): array
	{
		return [
			WordPressXmlDriver::getName()
		];
	}
}