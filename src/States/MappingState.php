<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\ImportModelMap;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\Concerns\HasStorageDriver;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\Concerns\AnalyzesValues;
use Crumbls\Importer\States\Concerns\StreamingAnalyzesValues;
use Crumbls\Importer\Support\MemoryManager;
use Crumbls\Importer\Facades\Storage;
use Crumbls\StateMachine\State;
use Exception;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MappingState extends AbstractState
{
	use HasStorageDriver;

    public function onEnter(): void
    {
    }

	public function execute() : bool {
        $record = $this->getRecord();

		// Get existing ImportModelMaps created by PostTypePartitioningState
		$importModelMaps = $this->getImportModelMaps($record);
		
		// Build mapping summary from comprehensive ImportModelMap data
		$mappingSummary = $this->buildMappingSummary($importModelMaps);
		
		// Update metadata with mapping summary
		$metadata = $record->metadata ?? [];
		$metadata['mapping_summary'] = $mappingSummary;
		$record->update(['metadata' => $metadata]);
		
		// Log mapping completion
		Log::info('Mapping state completed', [
			'import_id' => $record->id,
			'model_maps_count' => count($importModelMaps),
			'entities_mapped' => array_keys($mappingSummary)
		]);

		$this->transitionToNextState($record);

		return true;
    }

	public function onExit() : void {
	}

	/**
	 * Get existing ImportModelMaps for this import
	 */
	protected function getImportModelMaps(ImportContract $record): array
	{
		return ImportModelMap::where('import_id', $record->id)
			->where('is_active', true)
			->orderBy('priority', 'asc')
			->get()
			->toArray();
	}
	
	/**
	 * Build comprehensive mapping summary from ImportModelMap data
	 */
	protected function buildMappingSummary(array $importModelMaps): array
	{
		$summary = [];
		
		foreach ($importModelMaps as $modelMap) {
			$entityType = $modelMap['entity_type'];
			
			// Extract key information from comprehensive ImportModelMap structure
			$summary[$entityType] = [
				'entity_type' => $entityType,
				'target_model' => $modelMap['target_model'],
				'target_table' => $modelMap['target_table'],
				'driver' => $modelMap['driver'],
				'is_ready' => $this->isModelMapReady($modelMap),
				
				// Source information
				'source_info' => $modelMap['source_info'] ?? [],
				
				// Column mapping summary
				'columns' => $this->summarizeColumns($modelMap['schema_mapping'] ?? []),
				
				// Relationship summary  
				'relationships' => $this->summarizeRelationships($modelMap['relationships'] ?? []),
				
				// Conflict information
				'conflicts' => $this->summarizeConflicts($modelMap['conflict_resolution'] ?? []),
				
				// Validation rules summary
				'validation' => $this->summarizeValidation($modelMap['data_validation'] ?? []),
				
				// Model metadata summary
				'model_config' => $this->summarizeModelConfig($modelMap['model_metadata'] ?? []),
				
				// Performance configuration
				'performance' => $modelMap['performance_config'] ?? [],
				
				// Migration metadata
				'migration' => $modelMap['migration_metadata'] ?? []
			];
		}
		
		return $summary;
	}
	
	/**
	 * Check if ImportModelMap is ready for implementation
	 */
	protected function isModelMapReady(array $modelMap): bool
	{
		$required = ['target_model', 'target_table', 'schema_mapping'];
		
		foreach ($required as $field) {
			if (empty($modelMap[$field])) {
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Summarize column mappings
	 */
	protected function summarizeColumns(array $schemaMapping): array
	{
		$columns = $schemaMapping['columns'] ?? [];
		
		return [
			'total_count' => count($columns),
			'by_source' => $this->groupColumnsBySource($columns),
			'by_type' => $this->groupColumnsByType($columns),
			'nullable_count' => $this->countNullableColumns($columns)
		];
	}
	
	/**
	 * Summarize relationships
	 */
	protected function summarizeRelationships(array $relationships): array
	{
		$summary = [
			'total_count' => 0,
			'by_type' => []
		];
		
		foreach ($relationships as $type => $relations) {
			$count = count($relations);
			$summary['by_type'][$type] = $count;
			$summary['total_count'] += $count;
		}
		
		return $summary;
	}
	
	/**
	 * Summarize conflicts
	 */
	protected function summarizeConflicts(array $conflictResolution): array
	{
		return [
			'has_conflict' => $conflictResolution['conflict_detected'] ?? false,
			'strategy' => $conflictResolution['strategy'] ?? 'smart_extension',
			'safety_score' => $conflictResolution['existing_model_info']['safety_score'] ?? 1.0,
			'requires_confirmation' => $conflictResolution['extension_configuration']['require_confirmation'] ?? false
		];
	}
	
	/**
	 * Summarize validation rules
	 */
	protected function summarizeValidation(array $dataValidation): array
	{
		return [
			'required_fields_count' => count($dataValidation['required_fields'] ?? []),
			'validation_rules_count' => count($dataValidation['validation_rules'] ?? []),
			'cleaning_rules_count' => count($dataValidation['data_cleaning_rules'] ?? [])
		];
	}
	
	/**
	 * Summarize model configuration
	 */
	protected function summarizeModelConfig(array $modelMetadata): array
	{
		return [
			'has_timestamps' => $modelMetadata['timestamps'] ?? true,
			'fillable_count' => count($modelMetadata['fillable'] ?? []),
			'casts_count' => count($modelMetadata['casts'] ?? []),
			'traits' => $modelMetadata['traits'] ?? [],
			'interfaces' => $modelMetadata['interfaces'] ?? []
		];
	}
	
	/**
	 * Helper methods for column analysis
	 */
	protected function groupColumnsBySource(array $columns): array
	{
		$grouped = [];
		foreach ($columns as $column) {
			$source = $column['source_context'] ?? 'unknown';
			$grouped[$source] = ($grouped[$source] ?? 0) + 1;
		}
		return $grouped;
	}
	
	protected function groupColumnsByType(array $columns): array
	{
		$grouped = [];
		foreach ($columns as $column) {
			$type = $column['laravel_column_type'] ?? 'unknown';
			$grouped[$type] = ($grouped[$type] ?? 0) + 1;
		}
		return $grouped;
	}
	
	protected function countNullableColumns(array $columns): int
	{
		return count(array_filter($columns, fn($col) => $col['nullable'] ?? false));
	}
}