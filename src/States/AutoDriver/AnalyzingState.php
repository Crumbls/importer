<?php

namespace Crumbls\Importer\States\AutoDriver;

use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Console\Prompts\AutoDriver\AnalyzingStatePrompt;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\FailedState;
use Illuminate\Support\Arr;

class AnalyzingState extends AbstractState
{

    /**
     * Get the prompt class for viewing this state
     */
    public function getPromptClass(): string
    {
        return AnalyzingStatePrompt::class;
    }

    /**
     * Enable auto-transitions for this state
     */
    protected function hasAutoTransition(): bool
    {
        return true;
    }

	public function onEnter(): void
	{
	}

	public function execute(): bool
	{
		$import = $this->getRecord();

		\Log::info("AutoDriver AnalyzingState executing", [
			'import_id' => $import->getKey(),
			'source_type' => $import->source_type,
			'source_detail' => $import->source_detail,
			'current_driver' => class_basename($import->driver),
			'current_state' => class_basename($import->state)
		]);

		$metadata = $import->metadata ?? [];

		$availableDrivers = Importer::getAvailableDrivers();
		
		\Log::info("Available drivers for analysis", [
			'import_id' => $import->getKey(),
			'drivers' => $availableDrivers
		]);

		$driver = Arr::first($availableDrivers, function($driverName) use ($import) {
			$driverClass = Importer::driver($driverName);
			$canHandle = $driverClass::canHandle($import);
			
			\Log::debug("Testing driver compatibility", [
				'import_id' => $import->getKey(),
				'driver' => $driverName,
				'driver_class' => $driverClass,
				'can_handle' => $canHandle
			]);
			
			return $canHandle;
		});

		if (!$driver) {
			\Log::error("No compatible driver found", [
				'import_id' => $import->getKey(),
				'source_type' => $import->source_type,
				'source_detail' => $import->source_detail,
				'tested_drivers' => $availableDrivers
			]);
			
			$import->update([
				'state' => FailedState::class
			]);
			throw new CompatibleDriverNotFoundException();
		}

		$driverClass = Importer::driver($driver);
		$state = $driverClass::config()->getDefaultState();

		\Log::info("Compatible driver detected, transitioning", [
			'import_id' => $import->getKey(),
			'detected_driver' => $driver,
			'driver_class' => $driverClass,
			'new_state' => $state,
			'from_driver' => class_basename($import->driver),
			'from_state' => class_basename($import->state)
		]);

		$import->update([
			'driver' => $driverClass,
			'state' => $state
		]);

		$import->clearDriver();
		$import->clearStateMachine(); // Clear state machine cache to reload new state

		\Log::info("Driver transition completed", [
			'import_id' => $import->getKey(),
			'final_driver' => class_basename($import->driver),
			'final_state' => class_basename($import->state)
		]);

		return true;
	}

	public function onExit(): void
	{
	}

}