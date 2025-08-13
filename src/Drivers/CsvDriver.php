<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\Shared\CompletedState;
use Crumbls\Importer\States\Shared\MappingState;
use Crumbls\Importer\States\Shared\CreateStorageState;
use Crumbls\Importer\States\CsvDriver\AnalyzingState;
use Crumbls\Importer\States\CsvDriver\ExtractState;
use Crumbls\Importer\States\CsvDriver\PendingState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\Shared\ColumnTypeAnalysisState;
use Crumbls\Importer\Support\DriverConfig;
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


	public static function config(): DriverConfig
	{
		return (new DriverConfig())
			->default(PendingState::class)

			->allowTransition(PendingState::class, AnalyzingState::class)
			->allowTransition(PendingState::class, FailedState::class)

			->allowTransition(AnalyzingState::class, CreateStorageState::class)
			->allowTransition(AnalyzingState::class, FailedState::class)

			->allowTransition(CreateStorageState::class, ExtractState::class)
			->allowTransition(CreateStorageState::class, FailedState::class)

			->allowTransition(ExtractState::class, ColumnTypeAnalysisState::class)
			->allowTransition(ExtractState::class, FailedState::class)

			/*
			->allowTransition(ColumnTypeAnalysisState::class, AnalyzingState::class)
*/
				->allowTransition(ColumnTypeAnalysisState::class, MappingState::class)
			->allowTransition(ColumnTypeAnalysisState::class, FailedState::class)

			->preferredTransition(PendingState::class, AnalyzingState::class)
			->preferredTransition(AnalyzingState::class, CreateStorageState::class)
			->preferredTransition(CreateStorageState::class, ExtractState::class)
			->preferredTransition(ExtractState::class, ColumnTypeAnalysisState::class)
			->preferredTransition(ColumnTypeAnalysisState::class, MappingState::class)

//			->preferredTransition(\Crumbls\Importer\States\Shared\ColumnTypeAnalysisState::class, AnalyzingState::class)
			;
	}
}