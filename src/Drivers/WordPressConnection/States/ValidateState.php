<?php

namespace Crumbls\Importer\Drivers\WordPressConnection\States;

use Crumbls\Importer\States\AbstractState;

class ValidateState extends AbstractState {


	public function getName(): string {
		return 'validate';
	}

	public function handle(): void {
		dd(__LINE__);
	}
}