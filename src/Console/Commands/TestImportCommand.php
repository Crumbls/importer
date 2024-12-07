<?php

namespace Crumbls\Importer\Console\Commands;

use Crumbls\Importer\Drivers\WordPressXML\Stages\AnalyzeContentStage;
use Crumbls\Importer\Drivers\WordPressXML\Stages\ValidateFileStage;
use Illuminate\Console\Command;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Import;

class TestImportCommand extends Command
{
	protected $signature = 'importer:test 
                          {--file= : Specific file to test (optional)}
                          {--driver=wpxml : Driver to use for testing}';

	protected $description = 'Test the importer with WordPress XML files from the tests directory';

	public function handle()
	{
		if (true) {
			$record = Import::latest()->first();
			$logs = $record
				->logs()
				->where('level', 'debug')
				->orderBy('id')
				->get()
				->map(function($log) {
					return [
						'point' => $log->context['point'],
						'memory' => $log->context['memory_usage'],
						'peak' => $log->context['peak_memory']
					];
				});
//			dd($logs->slice($logs->count() - 10));
		}
		$testPath = base_path('import-tests');

		// Create directory if it doesn't exist
		if (!file_exists($testPath)) {
			if (!mkdir($testPath, 0755, true)) {
				$this->error("Could not create test directory: {$testPath}");
				return 1;
			}

			$this->info("Created test directory: {$testPath}");
		}

		if (true) {
			$import = Import::find(2);
			$driver = Importer::driver($import->driver)
				->import_id($import->id)  // Pass the import ID
				->file($import->config['file']);
			$j = new AnalyzeContentStage($driver);
			echo $import->getCurrentStage();
			var_export($j->handle($import));
			echo $import->getCurrentStage();
			dd($import->getCurrentStage());
			dd(get_class_methods($import));
		}

		// Get test file
		$file = $this->getTestFile($testPath);
		if (!$file) {
			$this->error('No test files found!');
			$this->info("Please add WordPress XML files to: {$testPath}");
			return 1;
		}

		$this->info('Starting import test...');
		$this->info("Using file: {$file}");
		$this->newLine();

		try {
			// Create initial Import record
			$import = Import::create([
				'driver' => $this->option('driver'),
				'status' => 'pending',
				'config' => [
					'file' => $file
				]
			]);

			// Start the import process with the Import ID
			Importer::driver($this->option('driver'))
				->import_id($import->id)  // Pass the import ID
				->file($file)
				->createJob();

			$this->info('Import job created successfully!');

			// Show initial status
			$this->table(
				['Field', 'Value'],
				[
					['ID', $import->id],
					['Status', $import->status],
					['File', $file],
					['Created At', $import->created_at]
				]
			);

		} catch (\Exception $e) {
			$this->error("Import failed: {$e->getMessage()}");
			$this->newLine();
			$this->error($e->getTraceAsString());
			return 1;
		}

		return 0;
	}

	protected function getTestFile(string $path): ?string
	{
		// If specific file is provided, use it
		if ($this->option('file')) {
			$specificFile = $path . '/' . $this->option('file');
			if (file_exists($specificFile)) {
				return $specificFile;
			}

			$this->error("Specified file not found: {$specificFile}");
			return null;
		}

		// Get all XML files from the directory
		$files = glob($path . '/*.xml');
		if (empty($files)) {
			return null;
		}

		// Pick a random file
		return $files[array_rand($files)];
	}
}