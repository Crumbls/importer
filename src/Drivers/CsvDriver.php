<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\States\AutoDriver\AnalyzingState;
use Crumbls\Importer\States\CancelledState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\InitializingState;
use Crumbls\Importer\States\CsvDriver\PendingState;
use Crumbls\StateMachine\Examples\PendingPayment;
use Crumbls\StateMachine\State;
use Crumbls\StateMachine\StateConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\PendingRequest;

class CsvDriver extends AbstractDriver
{

	/**
	 * TODO: Improve this.
	 * @param ImportContract $import
	 * @return bool
	 */
	public static function canHandle(ImportContract $import) : bool {
		if (!preg_match('#^file::#', $import->source_type)) {
			return false;
		}
		return preg_match('#\.csv#', $import->source_detail);
	}

	public static function getPriority() : int
	{
		return 100;
	}


	public static function config(): StateConfig
	{
		return parent::config()
			->default(PendingState::class)
			->allowTransition(PendingState::class, AnalyzingState::class)
			->allowTransition(AnalyzingState::class, FailedState::class)
			->allowTransition(AnalyzingState::class, CompletedState::class)
;
	}
}