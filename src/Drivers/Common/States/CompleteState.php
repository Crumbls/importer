<?php

namespace Crumbls\Importer\Drivers\Common\States;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ColumnMapper;
use PDO;
use Illuminate\Support\Str;

class CompleteState extends AbstractState
{

	public function getName(): string {
		return 'complete';
	}

	public function handle(): void {
		dump('we are all done.');
	}
}