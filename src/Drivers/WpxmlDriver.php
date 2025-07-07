<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\States\CreateStorageState;

use Crumbls\Importer\States\ProcessingState;

use Crumbls\Importer\States\CancelledState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\InitializingState;
use Crumbls\Importer\States\PendingState;
use Crumbls\StateMachine\Examples\PendingPayment;
use Crumbls\StateMachine\State;
use Crumbls\StateMachine\StateConfig;
use Crumbls\Importer\Support\DriverConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\PendingRequest;

class WpXmlDriver extends XmlDriver
{
	public static function getPriority() : int
	{
		return 90;
	}
	/**
	 * @param ImportContract $import
	 * @return bool
	 */
	public static function canHandle(ImportContract $import) : bool {
		// First check if it's a valid XML file
		if (!XmlDriver::canHandle($import)) {
			return false;
		}

		// Memory-efficient check for WordPress XML structure
		$filePath = $import->source_detail;
		if (!file_exists($filePath)) {
			return false;
		}

		// Read only the first 8KB to check for WordPress XML markers
		$handle = fopen($filePath, 'r');
		if (!$handle) {
			return false;
		}

		$chunk = fread($handle, 8192); // 8KB should be enough for XML header and root elements
		fclose($handle);

		// Check for WordPress export XML markers
		return strpos($chunk, '<rss') !== false && 
		       strpos($chunk, 'wordpress.org/export') !== false &&
		       strpos($chunk, '<wp:') !== false;
	}


	public static function config(): DriverConfig
	{
		return (new DriverConfig())
			->default(PendingState::class)

			->allowTransition(PendingState::class, CreateStorageState::class)
			->allowTransition(PendingState::class, FailedState::class)

			->allowTransition(CreateStorageState::class, ProcessingState::class)
			->allowTransition(CreateStorageState::class, FailedState::class)

			->allowTransition(ProcessingState::class, CompletedState::class)
			->allowTransition(ProcessingState::class, FailedState::class)


			->preferredTransition(PendingState::class, CreateStorageState::class)
			->preferredTransition(CreateStorageState::class, ProcessingState::class)
			->preferredTransition(ProcessingState::class, CompletedState::class);
	}
}