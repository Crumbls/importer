<?php

namespace Crumbls\Importer\Support;


namespace Crumbls\Importer\Support;

use Crumbls\Importer\Drivers\AbstractDriver;
use Crumbls\Importer\Facades\Importer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

abstract class BaseImporter
{
//	protected string $source;
//	protected string $driver;
	/**
	 * @var array
	 */
	protected array $steps;
	protected string $id;

	public function __construct()
	{
		$this->intialize();
	}


	protected function intialize() : void {
		$driver = $this->getDriver()
//			->setId($this->getId())
			;

		$this->configureDriver();

		/**
		 * Determine our current step.
		 */

		$state = $this->getState();
		/**
		 * First things first, we should create a driver that has every configurable option available.
		 * We create our pipeline.
		 * We add in our driver with any options.  The pipeline can have default steps, or we can define them.
		 * We always want to know our step in the pipeline.
		 * We want to standardize content if necessary. Most cases allow us to do a row by row import, but not all.
		 */

	}

	abstract protected function getDriverType() : string;

	protected function configureDriver() : void {}


	/**
	 * Nothing below is tested or working.
	 *
	 */
/*
	abstract protected function configure(): void;
*/
	public function getId(): string
	{
		if (isset($this->id) && $this->id) {
			return $this->id;
		}
		$this->id = uniqid('importer_');
		return $this->id;
	}

	public function pipeline(): ImportOrchestrator
	{
		return $this->orchestrator;
	}

	protected function getDriver() : ? AbstractDriver
	{
		return isset($this->driver) && $this->driver ? $this->driver : Importer::driver($this->getDriverType());
//			->setSource($this->source);
	}

	public function execute()
	{
		$driver = $this->getDriver();
		$driver->setDefaultState($this->getState());
		return $driver->execute($this);
	}

	public function getProgress(): int
	{
		return $this->orchestrator->getProgress();
	}

	/**
	 * Get the current state;
	 * @return string
	 */
	public function getState(): string {
		$state = Cache::get($this->getId().'_state');

		if (!$state) {
			$state = $this->getDriver()->getDefaultState();
			Cache::put($this->getId().'_state', $state);
		}

		return $state;

	}
}