<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\States\PendingState as BaseState;

class PendingState extends BaseState
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