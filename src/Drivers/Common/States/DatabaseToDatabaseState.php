<?php


namespace Crumbls\Importer\Drivers\Common\States;

use Crumbls\Importer\Models\ImportLog;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ModelAnalyzer;
use Crumbls\Importer\Traits\IsComposerAware;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use ReflectionClass;

/**
 * A state to create models from a database.
 */
class DatabaseToDatabaseState extends AbstractState
{
	use IsComposerAware;
	private ModelAnalyzer $analyzer;
	public function getName(): string
	{
		return 'database-to-database';
	}

	public function handle(): void
	{
		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		dump('TODO: Migrate data from the temporary database to the live one.');
	}


}