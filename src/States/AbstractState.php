<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Contracts\StateInterface;
use Crumbls\Importer\Drivers\AbstractDriver;

abstract class AbstractState implements StateInterface
{
	public function __construct(private AbstractDriver &$driver) {
	}

	public function getDriver() : AbstractDriver {
		return $this->driver;
	}

	abstract public function execute(): void;
	abstract public function canTransition(): bool;
	abstract public function getNextState(): ?string;
}