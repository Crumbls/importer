<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Illuminate\Console\Command;

abstract class AbstractPrompt
{
	public function __construct(protected Command $command, protected ?ImportContract $record = null)
	{
	}

	/**
	 * Clear the terminal screen for a clean interface
	 */
	protected function clearScreen(): void
	{
		$this->command->getOutput()->write("\033[2J\033[H");
	}

	public function info(string $message) : void {
		$this->command->getOutput()->info($message);
	}

	protected function transitionToNextState() : void {
		//$this->record->clearStateMachine();
		$stateMachine = $this->record->getStateMachine();
		$driverConfigClass = $this->record->driver;
		dd($driverConfigClass);
		$preferredTransitions = $driverConfigClass::config()->getPreferredTransitions();

		$state = $this->record->state;
		if (array_key_exists($state, $preferredTransitions)) {
			dd($preferredTransitions[$state]);
			}
	}

	/**
	 * Render the prompt - must be implemented by subclasses
	 */
	abstract public function render();
}