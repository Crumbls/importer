<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ColumnMapper;
use PDO;
use Illuminate\Support\Str;

/**
 * A state to create models from a database.
 * @deprecated
 */
class CreateModelsState extends AbstractState
{

	public function getName(): string {
		return 'create-models';
	}

	public function handle(): void {

		dd(__LINE__);
		$record = $this->getRecord();

		$md = $record->metadata ?? [];
		$md['tables'] = $md['tables'] ?? [];

		dd($md['tables']);
	}
}