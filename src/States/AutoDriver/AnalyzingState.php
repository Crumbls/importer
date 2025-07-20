<?php

namespace Crumbls\Importer\States\AutoDriver;

use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\FailedState;
use Illuminate\Support\Arr;

class AnalyzingState extends AbstractState
{

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

		$metadata = $import->metadata ?? [];

		$availableDrivers = Importer::getAvailableDrivers();

		$driver = Arr::first($availableDrivers, function($driverName) use ($import) {
			$driverClass = Importer::driver($driverName);
			return $driverClass::canHandle($import);
		});

		if (!$driver) {
			$import->update([
				'state' => FailedState::class
			]);
			throw new CompatibleDriverNotFoundException();
		}

		$driverClass = Importer::driver($driver);

		$state = $driverClass::config()->getDefaultState();

		$import->update([
			'driver' => $driverClass,
			'state' => $state
		]);

		$import->clearDriver();

		return true;
	}

	public function onExit(): void
	{
	}

}