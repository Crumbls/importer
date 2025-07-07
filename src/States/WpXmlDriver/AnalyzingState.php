<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\StateMachine\State;
use Illuminate\Support\Arr;

class AnalyzingState extends AbstractState
{
    public function onEnter() : void
    {
		$import = $this->getImport();
		return;
		dd($import);

	    $availableDrivers = Importer::getAvailableDrivers();

	    usort($availableDrivers, function($a, $b) {
		    $driverClassA = Importer::driver($a);
		    $driverClassB = Importer::driver($b);
		    return $driverClassA::getPriority() <=> $driverClassB::getPriority();
	    });

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

		// Update the import with the new driver
		$import->update([
			'driver' => $driverClass,
			'state' => $state
		]);

		// Clear the cached driver so it gets recreated with the new driver class
		$import->clearDriver();
    }
}