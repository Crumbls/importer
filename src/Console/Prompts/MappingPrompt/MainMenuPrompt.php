<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use Ramsey\Collection\Map\AbstractMap;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class MainMenuPrompt extends AbstractMappingPrompt
{

	public function render() : string
	{
		$this->clearScreen();

		// Check for conflicts before showing options
		$conflicts = $this->getConflicts();

		$conflictCount = count($conflicts);

		$options = [
			'browse' => 'Manage Entity Mappings',
		];

		// Always show continue option, but make it clear when disabled
		if ($conflictCount) {
			$options['conflict'] = 'Continue Import Process (resolve ' . $conflictCount . ' conflicts first)';
		} else {
			$options['continue'] = 'Continue Import Process';
		}

		$choice = select(
			'What would you like to do?',
			$options,
			default: 'browse'
		);

		// Handle disabled continue option
		if ($choice === 'continue' && $conflictCount) {
			$this->error('Cannot continue while conflicts exist. Please resolve conflicts first.');
			$this->command->newLine();
			return $this->render();
		}

		return $choice;
	}
}