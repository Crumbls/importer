<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\States\AbstractState;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

use Illuminate\Support\Facades\File;
use SplFileObject;
abstract class ExecuteMigrationState extends AbstractState
{

	/**
	 * Type detection rules
	 */
	private const TYPE_RULES = [
		'id' => 'bigIncrements',
		'email' => 'string',
		'password' => 'string',
		'created_at' => 'timestamp',
		'updated_at' => 'timestamp',
		'deleted_at' => 'timestamp'
	];

	public function execute(): void
	{
		$driver = $this->getDriver();

		$modelName = $driver->getParameter('model_name');

		$tableName = $driver->getParameter('table_name');

		if (!$tableName) {
			$tableName = class_exists($modelName) ? with(new $modelName)->getTable() : Str::plural(Str::snake(class_basename($modelName)));
			$driver->setParameter('table_name', $tableName);
		}

		// Check if table already exists
		if (Schema::hasTable($tableName)) {
			$driver->setParameter('migration_needed', false);
			return;
		}

		dd($driver->getAllParameters());
		dd($tableName);

	}
}