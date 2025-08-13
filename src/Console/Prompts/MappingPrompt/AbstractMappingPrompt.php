<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\MappingPrompt\MainMenuPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Contracts\ImportModelMapContract;
use Crumbls\Importer\Models\ImportModelMap;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

abstract class AbstractMappingPrompt extends AbstractPrompt
{

	public function getImportModelMaps() : Collection {
		return once(function() {
			$modelClass = ModelResolver::importModelMap();

			$modelMaps = $this->record->relationLoaded('modelMaps') ? $this->record->modelMaps : $modelClass::where('import_id', $this->record->id)
				->where('is_active', true)
				->orderBy('priority', 'asc')
				->get();

			return $modelMaps;
		});
	}

	protected function isReady(ImportModelMapContract $map): bool
	{
		return $map->isReady();
	}


	protected function getConflicts(): \Illuminate\Support\Collection
	{
		$modelClass = ModelResolver::importModelMap();
		$modelMaps = $this->record->relationLoaded('modelMaps')
			? $this->record->modelMaps
			: $modelClass::where('import_id', $this->record->id)
				->where('is_active', true)
				->get();

		$conflicts = [];

		foreach ($modelMaps as $map) {
			$conflictResolution = $map->conflict_resolution ?? [];
			$hasConflict = $conflictResolution['conflict_detected'] ?? false;

			if ($hasConflict) {
				$conflicts[] = [
					'id' => $map->getKey(),
					'entity_type' => $map->entity_type,
					'target_model' => $map->target_model,
					'conflict_info' => $conflictResolution['existing_model_info'] ?? [],
					'strategy' => $conflictResolution['strategy'] ?? 'smart_extension',
					'safety_score' => $conflictResolution['existing_model_info']['safety_score'] ?? 0.5,
				];
			}
		}

		return collect($conflicts);
	}
}