<?php


namespace Crumbls\Importer\Drivers\Common\States;
use Crumbls\Importer\States\AbstractState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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