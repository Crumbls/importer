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
use Crumbls\Importer\States\PendingState;
use Crumbls\Importer\Support\DriverConfig;
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

class AutoDriver extends AbstractDriver
{
	protected array $source = [];

	/**
	 * Set to false so we never auto attempt this. It's whole job is to find the correct driver.
	 * @param ImportContract $import
	 * @return bool
	 */
	public static function canHandle(ImportContract $import) : bool {
		return false;
	}

	public static function getPriority() : int
	{
		return 10;
	}


	public static function config(): DriverConfig
	{
		return (new DriverConfig())
			->default(PendingState::class)

			->allowTransition(PendingState::class, AnalyzingState::class)
			->allowTransition(AnalyzingState::class, FailedState::class)
//			->allowTransition(AnalyzingState::class, CompletedState::class)

			->preferredTransition(PendingState::class, AnalyzingState::class)
//			->preferredTransition(AnalyzingState::class, CompletedState::class)
			;
	}
}