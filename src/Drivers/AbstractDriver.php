<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Contracts\DriverInterface;
use Crumbls\Importer\Traits\HasImportConnection;
use Crumbls\Importer\Traits\HasStates;
use Crumbls\Importer\Transitions\GenericTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

abstract class AbstractDriver implements DriverInterface
{
	use HasImportConnection,
		HasStates;

	/**
	 * @param array $config
	 */
	public function __construct(protected array $config = [])
	{
	}

	/**
	 * Get the name of the driver
	 */
	abstract public static function getName(): string;

	/**
	 * Get the default state.
	 * @return string
	 * @throws \Exception
	 */
	public static function getStateDefault() : string {
		$states = static::getRegisteredStates();
		return $states[0] ?? throw new \Exception('No registered states found');
	}

	/**
	 * Get a list of registered states.
	 * @return array
	 */
	abstract public static function getRegisteredStates(): array;

	/**
	 * Get a list of registered transitions.
	 * @return array
	 */
	abstract public static function getRegisteredTransitions(): array;

	public function setRecord(Model $record) : self {
		$this->record = $record;
		$this->initializeState($record->state);
		return $this;
	}

	public function getRecord() : Model {
		return $this->record ?? throw new \Exception('No registered states found');
	}

	public function advance(): void
	{
		if (!$this->canAdvance()) {
			throw new \Exception("No available transitions from " . get_class($this->getState()));
		}

		$currentState = get_class($this->getState());
		$availableTransitions = static::getRegisteredTransitions()[$currentState];

		
		if (count($availableTransitions) > 1) {
			throw new \Exception("Multiple transitions available. Please use advanceTo() with one of: " . implode(', ', $availableTransitions));
		}

		$this->advanceTo($availableTransitions[0]);
	}

	public function advanceTo(string $stateClass): void
	{
		
		if (!$this->canAdvanceTo($stateClass)) {
			$currentState = get_class($this->getState());
			$availableTransitions = static::getRegisteredTransitions()[$currentState] ?? [];
			throw new \Exception("Invalid transition to {$stateClass} from {$currentState}. Available transitions: " . implode(', ', $availableTransitions));
		}
		
		$transition = (new GenericTransition($this))
			->setFrom(get_class($this->getState()))
			->setTo($stateClass);

		$this->transitionTo($transition);

		/**
		 * Handle our update.  We should move this to the transition.
		 */
		$record = $this->getRecord();

		if ($record) {
			$record->state = $stateClass;

			if ($record->exists()) {
				$record->update([
					'state' => $stateClass
				]);
			}
		}
	}

	public function canAdvance(): bool {
		$currentState = get_class($this->getState());
		$availableTransitions = static::getRegisteredTransitions()[$currentState] ?? [];

		return !empty($availableTransitions);
	}

	public function canAdvanceTo(string $stateClass): bool {
		$currentState = get_class($this->getState());
		$availableTransitions = static::getRegisteredTransitions()[$currentState] ?? [];

		return in_array($stateClass, $availableTransitions);
	}
}
