<?php

namespace Crumbls\Importer\Console\Commands;

use Crumbls\Importer\Steps\ProcessStep;
use Crumbls\Importer\Steps\ReadStep;
use Crumbls\Importer\Steps\TransformStep;
use Crumbls\Importer\Steps\ValidateStep;
use Crumbls\Importer\Support\BaseImporter;
use Crumbls\Importer\Support\ImportOrchestrator;
use Illuminate\Console\Command;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

class GenerateImporterCommand extends Command
{
	protected $signature = 'importer:generate 
                          {name : Name of the importer}
                          {--driver= : Driver to use}
                          {--model= : Model to import into}';

	protected $description = 'Generate a new importer class';

	public function handle()
	{
		$name = $this->argument('name');
		$driver = $this->option('driver');
		$model = $this->option('model');

		$file = new PhpFile;
		$file->setStrictTypes();

		$namespace = $file->addNamespace(app()->getNamespace().'Importers');

		$namespace->addUse(ImportOrchestrator::class);
		$namespace->addUse(ReadStep::class);
		$namespace->addUse(TransformStep::class);
		$namespace->addUse(ValidateStep::class);
		$namespace->addUse(ProcessStep::class);
		$namespace->addUse(BaseImporter::class);

		$class = $namespace->addClass($name);
		$class->setExtends(BaseImporter::class);

		// Add required imports

		// Configure method
		$configure = $class->addMethod('configure');
		$configure->setReturnType('void');

		$configureBody = <<<'EOT'
        $this->source = ''; // Define your source
        $this->driver = '%s'; // Define your driver
        
        // Define your pipeline steps
        $this->pipeline()
            ->addStep(new ReadStep($this->getId()))
            ->addStep(new TransformStep($this->getId(), [
                // Define your transformers
            ]))
            ->addStep(new ValidateStep($this->getId(), [
                // Define your validation rules
            ]))
            ->addStep(new ProcessStep($this->getId(), [
                // Define your model mapping
            ]));
        EOT;

		$configure->setBody(sprintf($configureBody, $driver ?? 'csv'));

		// Add transform method
		$transform = $class->addMethod('transform');
		$transform->setReturnType('array');
		$transform->addParameter('row')->setType('array');
		$transform->setBody('return $row;');

		// Add validate method
		$validate = $class->addMethod('validate');
		$validate->setReturnType('array');
		$validate->setBody('return [
    // Define your validation rules
];');

		// Add mapping method
		$mapping = $class->addMethod('mapping');
		$mapping->setReturnType('array');
		$mapping->setBody('return [
    // Define your model field mapping
];');

		$printer = new PsrPrinter;
		$output = $printer->printFile($file);

		$path = app_path('Importers/' . $name . '.php');
		if (!file_exists(dirname($path))) {
			mkdir(dirname($path), 0755, true);
		}

		file_put_contents($path, $output);

		$this->info('Importer created successfully at: ' . $path);
	}
}