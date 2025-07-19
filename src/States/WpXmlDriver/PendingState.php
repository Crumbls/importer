<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\Drivers\WpXmlDriver;
use Crumbls\Importer\Filament\Pages\GenericInfolistPage;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\Concerns\AutoTransitionsTrait;
use Crumbls\Importer\States\PendingState as BaseState;
use Crumbls\Importer\Support\StateMachineRunner;

class PendingState extends BaseState
{
	use AutoTransitionsTrait;

    /**
     * Format file size in human readable format
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) return 'Unknown';
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = floor(log($bytes, 1024));
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

	public function onEnter() : void {
		$record->clearStateMachine();
	}

	public function execute(): bool {
		$record = $this->getRecord();

		$driver = $record->getDriver();

		$config = $driver->config();

		$this->transitionToNextState($record);

		return true;
	}

	public function onExit() : void {
		dump(__LINE__);
	}

}