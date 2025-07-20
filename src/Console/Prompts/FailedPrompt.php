<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use function Laravel\Prompts\select;

class FailedPrompt extends AbstractPrompt
{

	public function render() : ?ImportContract
	{
//		$this->clearScreen();

		$record = $this->record;

		$errorMessage = $record->error_message;

		if ($errorMessage) {
			$this->command->error($errorMessage);
		} else {
			$this->command->error('Import failed.  No information was available.');
		}

		return $record;

	}

}