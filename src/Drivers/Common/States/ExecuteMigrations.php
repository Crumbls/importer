<?php


namespace Crumbls\Importer\Drivers\Common\States;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Traits\IsComposerAware;
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
	use IsComposerAware;

	public function getName(): string {
		return 'execute-migrations';
	}

	public function handle(): void {

		/**
		 * Due to the autoloader not being executed on creation, we bring every one of our models online.
		 */
		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$md['transformers'] = $md['transformers'] ?? [];

		foreach(array_column($md['transformers'], 'model_name') as $model) {
			if (!class_exists($model)) {
				include_once(static::getComposerPathToClass($model));
			}
		}

		\Artisan::call('migrate');
	}


}