<?php

namespace Crumbls\Importer\Concerns;

use Crumbls\Importer\Exceptions\StateNotFoundException;
use InvalidArgumentException;
use Illuminate\Support\Facades\Cache;

trait HasStateMachine
{
	private string $id;

	protected array $states = [];
	protected bool $isComplete = false;

	protected bool $isLocked = false;

	public function setId(string $id) : self {
		$this->id = $id;
		return $this;
	}

	public function getId() : string {
		return $this->id;
	}

	public function getCurrentState(): string
	{
		return $this->getParameter('current_state');
	}

	public function setCurrentState(string $state): void
	{
		if (!in_array($state, $this->states)) {
			throw new StateNotFoundException("Invalid state: {$state}");
		}
		$this->setParameter('current_state', $state);
	}

	public function isComplete(): bool
	{
		return $this->isComplete;
	}

	public function isLocked() : bool {
		return $this->isLocked;
	}

	public function execute(): void
	{
		$this->isLocked = false;

		if ($this->isComplete()) {
			return;
		}

		$currentState = $this->getCurrentState();

		$state = new $currentState($this);

		/**
		 * TODO: Add options in to the state machine.
		 */

		/**
		 * TODO: Trigger event.
		 */
		$state->execute();
		/**
		 * TODO: Trigger event.
		 */

		if ($state->canTransition()) {
			$nextState = $state->getNextState();
			if ($nextState === null) {
				$this->isComplete = true;
				/**
				 * TODO: Trigger event.
				 */
			} else {
				$this->setCurrentState($nextState);
				/**
				 * TODO: Trigger event.
				 */
			}
			/**
			 * Save
			 */
			$this->saveParameters();
		} else {
			$this->isLocked = true;
		}
	}


	public function loadParameters() {
		foreach((array)Cache::get($this->getId(), []) as $k => $v) {
			$this->setParameter($k, $v);
		}
		echo '<pre>';
		print_r($this);
		echo '</pre>';
		return $this;
	}

	public function saveParameters() : self {
		Cache::put($this->getId(), $this->getAllParameters(), 60 * 60);
		return $this;
	}

}