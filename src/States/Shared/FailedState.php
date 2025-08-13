<?php

namespace Crumbls\Importer\States\Shared;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Console\Prompts\FailedPrompt;
use Crumbls\Importer\States\Contracts\ImportStateContract;

class FailedState extends AbstractState implements ImportStateContract
{
    public function onEnter(): void
    {

    }

	public function execute() : bool {
		return false;
	}


	public function onExit(): void {

	}

	public static function getCommandPrompt() : string {
		return FailedPrompt::class;
	}
}