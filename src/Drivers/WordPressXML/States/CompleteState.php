<?php

namespace Crumbls\Importer\Drivers\WordPressXML\States;

use Crumbls\Importer\States\AbstractState;

class CompleteState extends AbstractState {

	public function getName(): string {
		return 'complete';
	}
	public function handle() : void {
		dump(__METHOD__);
	}

}