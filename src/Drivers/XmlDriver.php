<?php

namespace Crumbls\Importer\Drivers;

use Exception;
use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\States\AutoDriver\AnalyzingState;
use Crumbls\Importer\States\CancelledState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\InitializingState;
use Crumbls\Importer\States\XmlDriver\PendingState;
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

class XmlDriver extends AbstractDriver
{

	/**
	 * @param ImportContract $import
	 * @return bool
	 */
	public static function canHandle(ImportContract $record) : bool {
		if ($record->source_type !== 'storage') {
			return false;
		}
		
		// Check if source detail matches XML file pattern
		if (!preg_match('#^\w+::.*\.xml#i', $record->source_detail)) {
			return false;
		}
		
		// Extract disk and path from source detail
		[$disk, $path] = explode('::', $record->source_detail, 2);
		
		try {
			// Check if file exists
			if (!Storage::disk($disk)->exists($path)) {
				return false;
			}
			
			// Simple XML validation - just check if it starts with XML content
			$stream = Storage::disk($disk)->readStream($path);
			if (!$stream) {
				return false;
			}
			
			$firstChunk = fread($stream, 1024);
			fclose($stream);
			
			// Check for XML declaration or root element
			return $firstChunk && preg_match('/^\s*<\?xml|^\s*<\w+/', $firstChunk);
			
		} catch (Exception $e) {
			// If any error occurs during validation, we can't handle this file
			return false;
		}
	}

	public static function getPriority() : int
	{
		return WpXmlDriver::getPriority() + 10;
	}


	public static function config(): DriverConfig
	{
		throw new Exception('Not defined!');
		return (new DriverConfig())
			->default(PendingState::class)
			->allowTransition(PendingState::class, AnalyzingState::class)
			->allowTransition(AnalyzingState::class, FailedState::class)
			->allowTransition(AnalyzingState::class, CompletedState::class)
;
	}
}