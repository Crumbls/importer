<?php

namespace Crumbls\Importer\Console\Commands;

use Crumbls\Importer\Models\Import;
use Illuminate\Console\Command;

/**
 * You should ignore this.  It's whole goal is just to make testing with real data rapid.
 */
class TestImportCommand extends Command
{
	protected $signature = 'importer:test 
                          {--file= : Specific file to test (optional)}
                          {--driver=wpxml : Driver to use for testing}';

	protected $description = 'Test the importer with WordPress XML files from the tests directory';

	public function handle()
	{
		Import::each(function($record) {
			$record->delete();
		});
		foreach(glob(database_path().'/wp_import_*.sqlite') as $test) {
			@unlink($test);
		}

		$file = \Arr::random(glob(base_path('import-tests').'/wordpres*.*'));

		$ext = substr($file, strrpos($file, '.') + 1);

		$driverMap = [
			'xml' => 'wordpress-xml',
			'sql' => 'wordpress-sql'
		];

		$driver = $driverMap[$ext];

		$import = Import::firstOrCreate([
			'driver' => $driver,
			'source' => $file,
		]);

		$driver = $import->getDriver();

		while ($driver->canAdvance()) {
			$driver->advance();
		}
	}
}