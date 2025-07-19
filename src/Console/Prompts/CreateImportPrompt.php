<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use function Laravel\Prompts\select;

class CreateImportPrompt extends AbstractPrompt
{

	public function render() : ?ImportContract
	{
		$this->clearScreen();

		$sourceType = $this->selectSourceType();
		
		if (!$sourceType) {
			return null;
		}

		$method = 'select'.ucfirst($sourceType).'Source';

		if (!method_exists($this, $method)) {
			return null;
		}

		$ret = $this->$method();

		if (!$ret) {
			return null;
		}

		$modelClass = ModelResolver::import();

		$ret['completed_at'] = null;

		$record = $modelClass::firstOrCreate($ret);

		return $record;

	}
	
	protected function selectSourceType(): ?string
	{
		$sourceOptions = [
			'storage' => 'File Storage',
			'database' => 'Database Connection',
		];
		
		return select(
			label: __('Where is your data coming from?'),
			options: $sourceOptions,
			default: 'storage'
		);
	}

	protected function selectDatabaseConnection(): ?array
	{
		$prompt = new DatabaseConnectionPrompt($this->command);
		$selectedConnection = $prompt->select();

		if (!$selectedConnection) {
			return null;
		}

		return ['connection' => $selectedConnection];
	}

	protected function selectStorageSource(): ?array
	{
		$prompt = new FileBrowserPrompt($this->command);
		$path = $prompt->render();

		if (!$path) {
			return null;
		}

		return [
			'source_type' => 'storage',
			'source_detail' => $path
		];
	}
}