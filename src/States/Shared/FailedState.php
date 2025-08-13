<?php

namespace Crumbls\Importer\States\Shared;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Console\Prompts\FailedPrompt;

class FailedState extends AbstractState
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