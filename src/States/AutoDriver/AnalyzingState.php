<?php

namespace Crumbls\Importer\States\AutoDriver;

use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\FailedState;
use Illuminate\Support\Arr;

class AnalyzingState extends AbstractState
{
    public function onEnter(): void
    {
	    $import = $this->getImport();
        if (!$import instanceof ImportContract) {
            throw new \RuntimeException('Import contract not found in context');
        }

	    /** @var array<string> $availableDrivers */
        $availableDrivers = Importer::getAvailableDrivers();

        usort($availableDrivers, function(string $a, string $b): int {
            /** @var class-string $driverClassA */
            $driverClassA = Importer::driver($a);
            /** @var class-string $driverClassB */
            $driverClassB = Importer::driver($b);
            return $driverClassA::getPriority() <=> $driverClassB::getPriority();
        });

        /** @var string|null $driver */
        $driver = Arr::first($availableDrivers, function(string $driverName) use ($import): bool {
            /** @var class-string $driverClass */
            $driverClass = Importer::driver($driverName);
            return $driverClass::canHandle($import);
        });

        if ($driver === null) {
            $import->update([
                'state' => FailedState::class
            ]);
            throw new CompatibleDriverNotFoundException();
        }

        /** @var class-string $driverClass */
        $driverClass = Importer::driver($driver);

        /** @var class-string|null $state */
        $state = $driverClass::config()->getDefaultState();

		$import->driver = $driverClass;
		$import->state = $state;

        $import->update([
            'driver' => $driverClass,
            'state' => $state
        ]);

	    $import->clearDriver();
    }
}