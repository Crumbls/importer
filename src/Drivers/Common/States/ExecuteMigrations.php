<?php


namespace Crumbls\Importer\Drivers\Common\States;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ColumnMapper;
use Crumbls\Importer\Traits\HasTransformerDefinition;
use Crumbls\Importer\Traits\IsTableSchemaAware;
use PDO;
use Illuminate\Support\Str;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
/**
 * A state to create models from a database.
 */
class ExecuteMigrations extends AbstractState
{

	public function getName(): string {
		return 'execute-migrations';
	}

	public function handle(): void {
		\Artisan::call('migrate');
	}


}