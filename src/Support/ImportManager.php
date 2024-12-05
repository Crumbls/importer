<?php

// src/Support/ImportManager.php
namespace Crumbls\Importer\Support;

use Crumbls\Importer\Drivers\CsvDriver;
use Crumbls\Importer\Drivers\XmlDriver;
use Illuminate\Support\Manager;

class ImportManager extends Manager
{

	public function getDefaultDriver()
	{
		return $this->config->get('importer.default', 'csv');
	}

	/**
	 * @return CsvDriver
	 */
	public function createCsvDriver()
	{
		return new CsvDriver();
	}


	/**
	 * Creates a new Car driver
	 *
	 * @return \App\Transport\Car\Car
	 */
	public function createXmlDriver()
	{
		return new XmlDriver();
	}
}