<?php

namespace Crumbls\Importer\Drivers\WordPressSql\States;

use Crumbls\Importer\States\AbstractState;

class ValidateState extends AbstractState {


	public function getName(): string {
		return 'validate';
	}

	public function handle(): void {
		$source = $this->getRecord()->source;

// Open the SQL file
		$sqlFile = fopen($source, 'r');

// Check if the file contains any WordPress-related tables
		$containsWordPressTable = false;
		$wpTableNames = array('posts', 'postmeta', 'options');

		while (!feof($sqlFile)) {
			$line = fgets($sqlFile);
			foreach ($wpTableNames as $tableName) {
				if (strpos($line, $tableName) !== false) {
					$containsWordPressTable = true;
					break 2;
				}
			}
		}

// Close the SQL file
		fclose($sqlFile);

		if (!$containsWordPressTable) {
			throw new \Exception('Not a valid WordPress sql file.');
		}
	}
}