<?php

namespace Crumbls\Importer\Tasks;

use Crumbls\Importer\Contracts\DriverInterface;

class ImportTask
{
	protected DriverInterface $driver;

	public function __construct(DriverInterface $driver)
	{
		$this->driver = $driver;
	}

	public function source(string|array $source): self
	{
		$this->driver->setSource($source);
		return $this;
	}

	public function dispatch(): void
	{
		// Here we'll eventually create and dispatch the import job
	}
}