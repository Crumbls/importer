<?php

namespace Crumbls\Importer\Transitions;

use Crumbls\Importer\States\AbstractState;

class GenericTransition extends AbstractTransition {
	protected string $from = '';
	protected string $to = '';

	public function handle(): AbstractState {
		if (empty($this->from) || empty($this->to)) {
			throw new \Exception('Transition from/to states must be set before handling');
		}

		$this->beforeTransition();
		$toState = $this->to;

		$newState = new $toState($this->driver);
		$this->updateRecord();
		$newState->handle();
		$this->afterTransition();

		return $newState;
	}

	protected function updateRecord(): void {
		$record = $this->driver->getRecord();
		if ($record && $record->exists()) {
			$record->update(['state' => $this->to]);
		}
	}

	public function setFrom(string $from): self {
		$this->from = $from;
		return $this;
	}

	public function setTo(string $to): self {
		$this->to = $to;
		return $this;
	}
}