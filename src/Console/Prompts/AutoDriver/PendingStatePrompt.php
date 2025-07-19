<?php

namespace Crumbls\Importer\Console\Prompts\AutoDriver;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use function Laravel\Prompts\select;

class PendingStatePrompt extends AbstractPrompt
{

	public function render() : ?ImportContract
	{
		$this->clearScreen();

		$this->info(__('Current state').': '.$this->record->state);

		$stateMachine = $this->record->getStateMachine();
		$driverConfigClass = $this->record->driver;

		$state = $stateMachine->getCurrentState();

		$state->execute();

		return $this->record;
	}
}