<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AutoDriver\AnalyzingState;
use Crumbls\Importer\States\AutoDriver\PendingState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\Support\DriverConfig;

class AutoDriver extends AbstractDriver
{
	protected array $source = [];

	public static function canHandle(ImportContract $import): bool
	{
		return false;
	}

	public static function getPriority(): int
	{
		return 10;
	}

	public static function config(): DriverConfig
	{
		return (new DriverConfig())
			->default(PendingState::class)
			->allowTransition(PendingState::class, AnalyzingState::class)
			->allowTransition(AnalyzingState::class, FailedState::class)
			->preferredTransition(PendingState::class, AnalyzingState::class);
	}
}