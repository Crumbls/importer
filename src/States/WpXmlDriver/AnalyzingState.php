<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Facades\Importer;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\WordPressDriver\AnalyzingState as BaseState;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\Concerns\AutoTransitionsTrait;
use Crumbls\Importer\Facades\Storage;
use Crumbls\StateMachine\State;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

class AnalyzingState extends BaseState
{
    public function onEnter(): void
    {
		dump(__LINE__);
		return;
        // Call parent analysis logic
        parent::onEnter();
        
        // After analysis is complete, prepare data for transformation phase
        $this->prepareAnalysisForTransformation();

		$this->transitionToNextState($this->getRecord());
        // Transition to the Transform phase (MappingState)
//        $this->transitionToTransformPhase();
    }

	public function execute(): bool {
		dump(__LINE__);
		return true;
	}
	public function onExit() : void {
		dump(__LINE__);
	}

    protected function prepareAnalysisForTransformation(): void
    {
        $import = $this->getRecord();
        $metadata = $import->metadata ?? [];

		unset($metadata['extraction_started']);

        // Get the analyzed data
        $dataMap = $metadata['data_map'] ?? [];

        
        // Transform the data structure for the new ETL states
        $analysisData = [
            'post_types' => $this->extractPostTypes($dataMap),
            'meta_fields' => $this->extractMetaFields($dataMap),
            'post_columns' => $this->extractPostColumns($dataMap),
            'field_analysis' => $dataMap, // Keep original for reference
            'extraction_stats' => $metadata['parsing_stats'] ?? [],
        ];
        
        // Store in state data for next states
        $this->setStateData('analysis', $analysisData);
    }
    
    protected function extractPostTypes(array $dataMap): array
    {
        $postTypes = [];
        
        // For now, we'll need to determine post types from the storage
        // This is a simplified version - in a full implementation,
        // we'd analyze the actual post types from the data
        $storage = $this->getStorageDriver();
        
        if ($storage && method_exists($storage, 'db')) {
            $connection = $storage->db();
            
            // Get post type counts
            $postTypeCounts = $connection->table('posts')
                ->select('post_type')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('post_type')
                ->get()
                ->pluck('count', 'post_type')
                ->toArray();
                
            foreach ($postTypeCounts as $postType => $count) {
                $postTypes[$postType] = [
                    'count' => $count,
                    'type' => $postType,
                    'description' => ucfirst($postType) . ' content type',
                ];
            }
        }
        
        return $postTypes;
    }
    
    protected function extractMetaFields(array $dataMap): array
    {
        $metaFields = [];
        
        foreach ($dataMap as $field) {
            if (($field['field_type'] ?? '') === 'meta_field') {
                $metaFields[] = $field;
            }
        }
        
        return $metaFields;
    }
    
    protected function extractPostColumns(array $dataMap): array
    {
        $postColumns = [];
        
        foreach ($dataMap as $field) {
            if (($field['field_type'] ?? '') === 'post_column') {
                $postColumns[] = $field;
            }
        }
        
        return $postColumns;
    }
    
    protected function getStorageDriver()
    {
        $import = $this->getRecord();
        $metadata = $import->metadata ?? [];
        
        if (!isset($metadata['storage_driver'])) {
            return null;
        }
        
        return Storage::driver($metadata['storage_driver'])
            ->configureFromMetadata($metadata);
    }
}