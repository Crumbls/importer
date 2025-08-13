<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Contracts\ImportModelMapContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use function Laravel\Prompts\text;

class DetailedSummaryPrompt extends AbstractPrompt
{

	public function render() : string
	{
		$modelClass = ModelResolver::importModelMap();

		$modelMaps = $this->record->relationLoaded('modelMaps') ? $this->record->modelMaps : $modelClass::where('import_id', $this->record->id)
			->where('is_active', true)
			->orderBy('priority', 'asc')
			->get();

		$this->clearScreen();
		$this->command->info("Detailed Import Summary");
		$this->command->info("════════════════════════════");
		$this->command->newLine();

		$this->command->info("Statistics:");
		$this->command->line("  - Total Entities: " . $modelMaps->count());

		$totalColumns = 0;
		$totalRelationships = 0;
		$conflictCount = 0;

		foreach ($modelMaps as $map) {
			$totalColumns += count($map->schema_mapping['columns'] ?? []);

			$relationships = $map->relationships ?? [];

			foreach ($relationships as $relations) {
				$totalRelationships += count($relations);
			}

			if (($map->conflict_resolution['conflict_detected'] ?? false)) {
				$conflictCount++;
			}
		}

		$this->command->line("  - Total Columns: {$totalColumns}");
		$this->command->line("  - Total Relationships: {$totalRelationships}");
		$this->command->line("  - Conflicts Detected: {$conflictCount}");
		$this->command->newLine();

		$this->command->info("Entity Breakdown:");

		foreach ($modelMaps as $map) {
			$status = $this->isReady($map) ? '[READY]' : '[NOT READY]';
			$columnCount = count($map->schema_mapping['columns'] ?? []);
			$relationshipCount = 0;
			foreach (($map['relationships'] ?? []) as $relations) {
				$relationshipCount += count($relations);
			}
continue;
			$this->command->line("  {$status} {$map['entity_type']}:");
			$this->command->line("    Model: {$map['target_model']}");
			$this->command->line("    Columns: {$columnCount}");
			$this->command->line("    Relationships: {$relationshipCount}");
		}

		$this->command->newLine();
		$this->command->info("Press Enter to continue...");
		text('', hint: 'Press Enter...');
		
		return ''; // Method handles console output directly
	}


}