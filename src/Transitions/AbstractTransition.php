<?php

namespace Crumbls\Importer\Transitions;

use Crumbls\Importer\Contracts\DriverInterface;
use Crumbls\Importer\States\AbstractState;

abstract class AbstractTransition
{
	protected string $from;
	protected string $to;

	public function __construct(protected DriverInterface $driver) {}

	public function getFrom(AbstractState $state): bool {
		if (empty($this->from)) {
			throw new \InvalidArgumentException(
				"Source state not set. Use setFrom() before attempting transition."
			);
		}

		$isValid = $state instanceof $this->from;

		if (!$isValid) {
			throw new \InvalidArgumentException(sprintf(
				"Invalid state transition. Expected source state '%s', but got '%s'",
				$this->from,
				get_class($state)
			));
		}

		return true;
	}

	public function handle(): AbstractState {
		$this->beforeTransition();
		$toState = $this->to;
		$this->updateRecord();
		$this->afterTransition();
		return new $toState($this->driver);
	}

	protected function updateRecord(): void {
		$record = $this->driver->getRecord();

		if ($record) {
			$record->state = $this->to;

			if ($record->exists()) {
				$record->update([
					'state' => $this->to
				]);
			}
		}
	}

	protected function beforeTransition(): void {}
	protected function afterTransition(): void {}
}
