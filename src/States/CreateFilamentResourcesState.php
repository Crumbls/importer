<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ColumnMapper;
use PDO;
use Illuminate\Support\Str;

class CreateFilamentResourcesState extends AbstractState
{

	public function getName(): string {
		return 'create-migrations';
	}

	public function handle(): void {

		$record = $this->getRecord();

		$md = $record->metadata ?? [];
		$md['tables'] = $md['tables'] ?? [];

		dd($md['tables']);
	}
}