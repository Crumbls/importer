<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Contracts\DriverInterface;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractState {
	public function __construct(protected DriverInterface $driver) {}
	abstract public function getName(): string;
	abstract public function handle(): void;


	public function getDriver() : DriverInterface {
		return $this->driver;
	}

	public function getRecord() : Model {
		return $this->getDriver()->getRecord() ?? throw new \Exception('Model not found.');
	}
}
