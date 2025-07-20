<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AutoDriver\AnalyzingState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\CsvDriver\PendingState;
use Crumbls\Importer\States\FailedState;
use Crumbls\StateMachine\StateConfig;
use Exception;
use Illuminate\Support\Facades\Storage;

class CsvDriver extends AbstractDriver
{

	public static function canHandle(ImportContract $record): bool
	{
		if ($record->source_type !== 'storage') {
			return false;
		}

		if (!preg_match('#^\w+::.*\.csv$#i', $record->source_detail)) {
			return false;
		}

		[$disk, $path] = explode('::', $record->source_detail, 2);

		try {
			if (!Storage::disk($disk)->exists($path)) {
				return false;
			}

			$stream = Storage::disk($disk)->readStream($path);
			if (!$stream) {
				return false;
			}

			fclose($stream);
			return true;

		} catch (Exception $e) {
			return false;
		}
	}

	public static function getPriority(): int
	{
		return 100;
	}

	public static function config(): StateConfig
	{
		return parent::config()
			->default(PendingState::class)
			->allowTransition(PendingState::class, AnalyzingState::class)
			->allowTransition(AnalyzingState::class, FailedState::class)
			->allowTransition(AnalyzingState::class, CompletedState::class);
	}
}