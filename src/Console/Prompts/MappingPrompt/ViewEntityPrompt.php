<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Contracts\ImportModelMapContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ViewEntityPrompt extends AbstractMappingPrompt
{

	public function __construct(
		protected Command $command,
		protected ?ImportContract $record = null,
		protected ImportModelMapContract $recordMap
	) {
		parent::__construct($command, $record);
	}

	public function render() : void
	{
		$modelClass = ModelResolver::importModelMap();
		
		if (!$this->recordMap) {
			$this->command->error("Entity not found: {$entityType}");
			return;
		}

		while (true) {
			$this->clearScreen();
			$this->command->info("Entity Details: {$this->recordMap->entity_type}");
			$this->command->info("═══════════════════════════════════");
			$this->command->newLine();

			// Basic info
			$this->command->info("Target Model: {$this->recordMap['target_model']}");
			$this->command->info("Target Table: {$this->recordMap['target_table']}");
			$this->command->info("Driver: {$this->recordMap['driver']}");
			$this->command->info("Status: " . ($this->isReady($this->recordMap) ? '[READY]' : '[NOT READY]'));
			$this->command->newLine();

			// Source info
			if (!empty($this->recordMap['source_info'])) {
				$sourceInfo = $this->recordMap['source_info'];
				$this->command->info("Source Information:");
				if (isset($sourceInfo['entity_count'])) {
					$this->command->line("  Records: {$sourceInfo['entity_count']}");
				}
				if (isset($sourceInfo['driver_metadata']['meta_field_count'])) {
					$this->command->line("  Meta Fields: {$sourceInfo['driver_metadata']['meta_field_count']}");
				}
				$this->command->newLine();
			}

			$options = [
				'columns' => 'View Column Mappings',
				'relationships' => 'View Relationships',
				'conflicts' => 'View Conflicts',
				'validation' => 'View Validation Rules',
				'back' => 'Back to Entity List'
			];

			$choice = select(
				'What would you like to view?',
				$options
			);

			Log::info(__LINE__);
			exit;

			switch ($choice) {
				case 'columns':
					$this->showColumnMappings($this->recordMap);
					break;
				case 'relationships':
					$this->showEntityRelationships($this->recordMap);
					break;
				case 'conflicts':
					$this->showConflictDetails($this->recordMap);
					break;
				case 'validation':
					$this->showValidationRules($this->recordMap);
					break;
				case 'back':
					return;
			}
		}
	}


	protected function showColumnMappings(array $recordMap): void
	{
		$this->clearScreen();
		$this->command->info("Column Mappings: {$this->recordMap['entity_type']}");
		$this->command->info("════════════════════════════════════");
		$this->command->newLine();

		$columns = $this->recordMap['schema_mapping']['columns'] ?? [];

		if (empty($columns)) {
			$this->command->warn("No column mappings found.");
		} else {
			$this->command->info("Total Columns: " . count($columns));
			$this->command->newLine();

			// Group by source context
			$groupedColumns = [];
			foreach ($columns as $columnName => $columnData) {
				$source = $columnData['source_context'] ?? 'unknown';
				$groupedColumns[$source][] = ['name' => $columnName, 'data' => $columnData];
			}

			foreach ($groupedColumns as $source => $sourceColumns) {
				$this->command->info("{$source} ({" . count($sourceColumns) . " columns):");
				foreach ($sourceColumns as $column) {
					$type = $column['data']['laravel_column_type'] ?? 'unknown';
					$nullable = ($column['data']['nullable'] ?? false) ? ' (nullable)' : '';
					$this->command->line("  - {$column['name']} -> {$type}{$nullable}");
				}
				$this->command->newLine();
			}
		}

		$this->command->info("Press Enter to continue...");
		text('', hint: 'Press Enter...');
	}


	protected function showEntityRelationships(): void
	{
		$this->clearScreen();
		$this->command->info("Relationships: {$this->recordMap['entity_type']}");
		$this->command->info("═══════════════════════════════════");
		$this->command->newLine();

		$relationships = $this->recordMap['relationships'] ?? [];

		if (empty($relationships)) {
			$this->command->warn("No relationships found for this entity.");
		} else {
			foreach ($relationships as $relationType => $relations) {
				if (!empty($relations)) {
					$this->command->info("{$relationType} (" . count($relations) . "):");
					foreach ($relations as $relationName => $relationData) {
						$relatedModel = $relationData['related_model'] ?? 'Unknown';
						$foreignKey = $relationData['foreign_key'] ?? 'Unknown';
						$this->command->line("  - {$relationName} -> {$relatedModel} (via {$foreignKey})");
					}
					$this->command->newLine();
				}
			}
		}

		$this->command->info("Press Enter to continue...");
		text('', hint: 'Press Enter...');
	}


	protected function showConflictDetails(): void
	{
		$this->clearScreen();
		$this->command->info("Conflict Details: {$this->recordMap['entity_type']}");
		$this->command->info("════════════════════════════════════");
		$this->command->newLine();

		$conflictResolution = $this->recordMap['conflict_resolution'] ?? [];
		$hasConflict = $conflictResolution['conflict_detected'] ?? false;

		if (!$hasConflict) {
			$this->command->info("[SAFE] No conflicts detected - safe to proceed!");
		} else {
			$strategy = $conflictResolution['strategy'] ?? 'unknown';
			$safetyScore = $conflictResolution['existing_model_info']['safety_score'] ?? 0;

			$this->command->warn("[CONFLICT] Model conflict detected!");
			$this->command->info("Strategy: {$strategy}");
			$this->command->info("Safety Score: {$safetyScore}");

			if (isset($conflictResolution['existing_model_info']['class_path'])) {
				$this->command->info("Existing Model: {$conflictResolution['existing_model_info']['class_path']}");
			}
		}

		$this->command->newLine();
		$this->command->info("Press Enter to continue...");
		text('', hint: 'Press Enter...');
	}

	protected function showValidationRules(): void
	{
		$this->clearScreen();
		$this->command->info("Validation Rules: {$this->recordMap['entity_type']}");
		$this->command->info("════════════════════════════════════");
		$this->command->newLine();

		$validation = $this->recordMap['data_validation'] ?? [];

		if (!empty($validation['required_fields'])) {
			$this->command->info("Required Fields:");
			foreach ($validation['required_fields'] as $field) {
				$this->command->line("  - {$field}");
			}
			$this->command->newLine();
		}

		if (!empty($validation['validation_rules'])) {
			$this->command->info("Validation Rules:");
			foreach ($validation['validation_rules'] as $field => $rule) {
				$this->command->line("  - {$field}: {$rule}");
			}
			$this->command->newLine();
		}

		if (!empty($validation['data_cleaning_rules'])) {
			$this->command->info("Data Cleaning Rules:");
			foreach ($validation['data_cleaning_rules'] as $field => $rule) {
				$this->command->line("  - {$field}: {$rule}");
			}
		}

		$this->command->newLine();
		$this->command->info("Press Enter to continue...");
		text('', hint: 'Press Enter...');
	}
}