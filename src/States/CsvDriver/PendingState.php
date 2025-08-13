<?php

namespace Crumbls\Importer\States\CsvDriver;

use Crumbls\Importer\States\Contracts\ImportStateContract;
use Crumbls\Importer\States\PendingState as BaseState;

class PendingState extends BaseState implements ImportStateContract
{

	public function onEnter(): void
	{
		$record = $this->getRecord();
		$record->clearStateMachine();
	}

	public function execute(): bool
	{
		$record = $this->getRecord();
		$this->transitionToNextState($record);
		return true;
	}
}