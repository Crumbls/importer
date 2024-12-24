<?php

namespace Crumbls\Importer\Drivers\Common\States;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ColumnMapper;
use PDO;
use Illuminate\Support\Str;

class CreateFilamentResourcesState extends AbstractState
{

	public function getName(): string {
		return 'create-filament-resources';
	}

	public function handle(): void {

		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$md['transformers'] = $md['transformers'] ?? [];

		collect( array_column($md['transformers'], 'model_name'))
			->map(function(string $model) {
				return class_basename($model);
			})
			->each(function(string $model) {
				\Artisan::call('filament:resource '.$model.' --generate');
			});
	}
}