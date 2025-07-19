<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use function Laravel\Prompts\select;

class StateInformerPrompt extends AbstractPrompt
{

	public function render() : ?ImportContract
	{
		$this->clearScreen();

		$this->info(__('Current state').': '.$this->record->state);

		return $this->record;
	}

	/**
 *
	 * $this->clearScreen();
	 *
	 * $this->record->clearStateMachine();
	 *
	 * $stateMachine = $this->record->getStateMachine();
	 *
	 * $driverConfigClass = $this->record->driver;
	 *
	 * $preferredTransitions = $driverConfigClass::config()->getPreferredTransitions();
	 *
	 * $state = $this->record->state;
	 *
	 * if (array_key_exists($state, $preferredTransitions)) {
	 *
	 * dd($preferredTransitions[$state]);
	 * }
	 * //        dd($preferredTransitions);
	 */
}