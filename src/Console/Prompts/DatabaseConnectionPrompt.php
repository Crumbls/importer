<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use function Laravel\Prompts\select;

class DatabaseConnectionPrompt extends AbstractPrompt
{

	public function render(): ?string
	{
		$this->clearScreen();
		return $this->select();
	}

	public function select(): ?string
	{
		$connections = config('database.connections');
		$connectionOptions = [];

		foreach ($connections as $name => $config) {
			// Test if connection works
			try {
				DB::connection($name)->getPdo();
				$connectionOptions[$name] = $config['driver'] . ' - ' . ($config['database'] ?? $config['host'] ?? $name);
			} catch (\Exception $e) {
				// Skip connections that don't work
				continue;
			}
		}

		if (empty($connectionOptions)) {
			$this->command->error('No working database connections found.');
			return null;
		}

		return select(
			label: __('Which database connection would you like to use?'),
			options: $connectionOptions
		);
	}
}