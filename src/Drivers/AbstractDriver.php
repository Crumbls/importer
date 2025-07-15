<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\StateMachine\State;
use Crumbls\StateMachine\StateMachine;

abstract class AbstractDriver extends State implements DriverContract
{

	public static function fromModel(ImportContract $record) : static {
		// Create a state machine and return this driver as the state
		$stateMachine = new StateMachine(static::class, ['model' => $record]);
		return new static($stateMachine, ['model' => $record]);
	}

	public static function getPriority() : int
	{
		return 100;
	}
}