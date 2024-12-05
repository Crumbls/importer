<?php


namespace Crumbls\Importer\Console\Commands;

use Crumbls\Importer\Steps\ModelDetectionStep;
use Crumbls\Importer\Steps\ProcessStep;
use Crumbls\Importer\Steps\ReadStep;
use Crumbls\Importer\Steps\TransformStep;
use Crumbls\Importer\Steps\ValidateStep;
use Illuminate\Console\Command;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Support\ImportOrchestrator;

class ImportCommand extends Command
{
	protected $signature = 'importer:run 
                          {source : Source file/connection to import from}
                          {--driver= : Driver to use for import}
                          {--model= : Model to import into}
                          {--batch=1000 : Batch size for processing}
                          {--generate-models=true : Generate those models}';

	protected $description = 'Run an import process';

	public function handle()
	{
		$source = $this->argument('source');
		$driver = $this->option('driver');
		$model = $this->option('model');
		$model = $model ?? '';
		$batchSize = $this->option('batch');
		$shouldGenerate = $this->option('generate-models');

		$importId = uniqid('import_');

		try {
			$driver = Importer::driver($driver)->setSource($source);

			$orchestrator = ImportOrchestrator::create($importId);

			$orchestrator
				->addStep(new ReadStep($importId, $driver, $batchSize))
				->addStep(new TransformStep($importId))
				->addStep(new ModelDetectionStep($importId, [], $shouldGenerate))
				->addStep(new ValidateStep($importId, []))
				->addStep(new ProcessStep($importId, []));

			$orchestrator->execute();

			$this->info('Import completed successfully');
		} catch (\Exception $e) {
			$this->error('Import failed: ' . $e->getMessage());
		}
	}
}
