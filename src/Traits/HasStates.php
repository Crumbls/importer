<?php

namespace Crumbls\Importer\Traits;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Transitions\AbstractTransition;
use Illuminate\Database\Eloquent\Model;

trait HasStates {
	protected AbstractState $state;

	abstract public function getRecord() : Model;

	public function getState(): AbstractState {
		return $this->state;
	}

	/**
	 * @param string|AbstractTransition $transition
	 * @return void
	 * @throws \Exception
	 */
	public function transitionTo(string|AbstractTransition $transition): void {
		$transitionInstance = is_string($transition)
			? new $transition($this)
			: $transition;

		if (!$transitionInstance->getFrom($this->state)) {
			throw new \Exception("Invalid state transition from " . get_class($this->state));
		}
		$this->state = $transitionInstance->handle();
	}

	abstract public static function getRegisteredStates(): array;
	abstract public static function getRegisteredTransitions(): array;

	protected function initializeState(string $stateClass): void {
		if (!in_array($stateClass, static::getRegisteredStates())) {
			throw new \InvalidArgumentException("Invalid state class: {$stateClass}");
		}

		$this->state = new $stateClass($this);
		$this->state->handle();
	}
}
