<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\States\PendingState as BaseState;
use Crumbls\Importer\Console\Prompts\Shared\GenericAutoPrompt;

class PendingState extends BaseState
{
	/**
	 * Get the prompt class for viewing this state
	 */
	public function getPromptClass(): string
	{
		return GenericAutoPrompt::class;
	}

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