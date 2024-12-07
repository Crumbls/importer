<?php

namespace Crumbls\Importer\States\Csv;

use Crumbls\Importer\States\AbstractState;
use Illuminate\Support\Str;
class DefineModelState extends AbstractState
{
	public function execute(): void
	{
		$driver = $this->getDriver();

		$modelName = $driver->getParameter('model_name');

		// If model name already exists and is valid, skip generation
		if ($modelName && $this->isValidModelName($modelName)) {
			return;
		}

		// Get the file name without extension to use as base
		$filePath = $driver->getParameter('file_path');

		$baseFileName = pathinfo($filePath, PATHINFO_FILENAME);

		// Generate model name from file name
		$modelName = $this->generateModelName($baseFileName);
		$driver->setParameter('model_name', $modelName);
	}

	public function canTransition(): bool
	{
		return $this->getDriver()->getParameter('model_name') !== null;
	}

	public function getNextState(): ?string
	{
		return GenerateMigrationState::class; // Or your next state
	}

	protected function generateModelName(string $input): string
	{
		// Remove special characters and spaces, keeping alphanumeric only
		$name = preg_replace('/[^a-zA-Z0-9\s]/', '', $input);

		// Convert to StudlyCase
		$name = Str::studly($name);

		// Ensure it's singular
		$name = Str::singular($name);

		// Remove any numbers from the start
		$name = preg_replace('/^[0-9]+/', '', $name);

		// If empty after cleaning, use default
		if (empty($name)) {
			$name = 'ImportedModel';
		}

		$name = app()->getNamespace().'Models\\'.$name;

		return $name;
	}

	protected function isValidModelName(string $name): bool
	{
		return preg_match('/^[A-Z][A-Za-z]*$/', $name) === 1;
	}

}