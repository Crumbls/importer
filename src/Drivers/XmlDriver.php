<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AutoDriver\AnalyzingState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\Shared\FailedState;
use Crumbls\Importer\States\XmlDriver\PendingState;
use Crumbls\Importer\Support\DriverConfig;
use Exception;
use Illuminate\Support\Facades\Storage;

class XmlDriver extends AbstractDriver
{
	public static function canHandle(ImportContract $record): bool
	{
		if ($record->source_type !== 'storage') {
			return false;
		}

		if (!preg_match('#^\w+::.*\.xml$#i', $record->source_detail)) {
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

			$firstChunk = fread($stream, 1024);
			fclose($stream);

			return $firstChunk && preg_match('/^\s*<\?xml|^\s*<\w+/', $firstChunk);

		} catch (Exception $e) {
			return false;
		}
	}

	public static function getPriority(): int
	{
		return WpXmlDriver::getPriority() + 10;
	}

	public static function config(): DriverConfig
	{
		throw new Exception('Not defined!');
	}
}