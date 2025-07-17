<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Services\ModelScanner;
use Crumbls\Importer\Filament\Pages\GenericFormPage;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Grid;
use Illuminate\Support\Str;

class MappingState extends AbstractState
{
    protected ModelScanner $scanner;

	protected function getModelScanner() : ModelScanner {
		if (!isset($this->scanner)) {
			$this->scanner = new ModelScanner();
		}
		return $this->scanner;
	}
    
    public function label(): string
    {
        return 'Transform - Mapping';
    }
    
    public function description(): string
    {
        return 'Map WordPress post types to Laravel models';
    }
    
    public function canEnter(): bool
    {
        return $this->getStateData('analysis') !== null;
    }
    
    public function onEnter(): void
    {
        $this->generateModelMappings();
        
        // Check if we can auto-skip this state
        if ($this->shouldAutoSkip()) {
            $this->handleAutoSkip();
        }
    }
    
    protected function shouldAutoSkip(): bool
    {
        $mappingData = $this->getStateData('model_mapping');
        
        if (!$mappingData || !isset($mappingData['mappings'])) {
            return false;
        }
        
        // Check if all post types have high-confidence model matches
        foreach ($mappingData['mappings'] as $postType => $mapping) {
            if (!$mapping['model_class'] || !$mapping['auto_mapped'] || $mapping['confidence'] !== 'high') {
                return false;
            }
        }
        
        // All post types have confident mappings
        return true;
    }
    
    protected function handleAutoSkip(): void
    {
        // Log the auto-skip for transparency
        $mappingData = $this->getStateData('model_mapping');
        $autoMappedCount = count($mappingData['mappings']);
        
        $this->setStateData('mapping_auto_skipped', [
            'skipped' => true,
            'reason' => 'All post types automatically mapped to existing models',
            'mapped_count' => $autoMappedCount,
            'timestamp' => now(),
        ]);
        
        // Determine next state based on whether models need to be created
        $unmappedTypes = $this->getUnmappedPostTypes($mappingData['mappings']);
        
        if (!empty($unmappedTypes)) {
            $this->setStateData('unmapped_for_creation', $unmappedTypes);
            $this->transitionTo(ModelCreationState::class);
        } else {
            $this->transitionTo(ModelCustomizationState::class);
        }
    }
    
    protected function generateModelMappings(): void
    {

        $analysisData = $this->getStateData('analysis');
        
        if (!$analysisData || !isset($analysisData['post_types'])) {
            $this->setStateData('mapping_error', 'No post type analysis data found');
            return;
        }
        
        $postTypes = $analysisData['post_types'];

		$scanner = $this->getModelScanner();

        $modelMatches = $scanner->findModelMatches($postTypes);
        $suggestions = $scanner->suggestModelNames(array_keys($postTypes));
        
        $mappingData = [
            'post_types' => $postTypes,
            'model_matches' => $modelMatches,
            'suggestions' => $suggestions,
            'mappings' => $this->generateDefaultMappings($modelMatches),
            'unmapped_types' => $this->findUnmappedTypes($modelMatches),
        ];
        
        $this->setStateData('model_mapping', $mappingData);
    }
    
    protected function generateDefaultMappings(array $modelMatches): array
    {
        $mappings = [];
        
        foreach ($modelMatches as $postType => $matches) {
            if (!empty($matches) && $matches[0]['confidence'] === 'high') {
                // Auto-map high confidence matches
                $mappings[$postType] = [
                    'model_class' => $matches[0]['model']['class'],
                    'auto_mapped' => true,
                    'confidence' => $matches[0]['confidence'],
                ];
            } else {
                // Leave for manual mapping
                $mappings[$postType] = [
                    'model_class' => null,
                    'auto_mapped' => false,
                    'confidence' => null,
                ];
            }
        }
        
        return $mappings;
    }
    
    protected function findUnmappedTypes(array $modelMatches): array
    {
        $unmapped = [];
        
        foreach ($modelMatches as $postType => $matches) {
            if (empty($matches) || $matches[0]['confidence'] !== 'high') {
                $unmapped[] = $postType;
            }
        }
        
        return $unmapped;
    }
    
    public function getRecommendedPageClass(): string
    {
        return GenericFormPage::class;
    }
    
    public function form(Schema $schema): Schema
    {
        $mappingData = $this->getStateData('model_mapping');
        
        if (!$mappingData) {
            return $schema->schema([
                Placeholder::make('error')
                    ->content('No mapping data available. Please go back to analysis.')
            ]);
        }
        
        return $schema->schema([
            Section::make('Post Type to Model Mapping')
                ->description('Map WordPress post types to your Laravel models. High confidence matches are pre-selected.')
                ->schema([
                    $this->buildMappingRepeater($mappingData),
                ]),
                
            Section::make('Unmapped Post Types')
                ->description('These post types need models created or manual mapping.')
                ->schema([
                    $this->buildUnmappedSection($mappingData),
                ])
                ->visible(fn() => !empty($mappingData['unmapped_types'])),
        ]);
    }
    
    protected function buildMappingRepeater(array $mappingData): Repeater
    {
		$scanner = $this->getModelScanner();
        $availableModels = $scanner->discoverModels();
        $modelOptions = collect($availableModels)->mapWithKeys(fn($model) => [
            $model['class'] => $model['name'] . ' (' . $model['class'] . ')'
        ]);
        
        return Repeater::make('model_mappings')
            ->label('Model Mappings')
            ->schema([
                Grid::make(3)->schema([
                    TextInput::make('post_type')
                        ->label('Post Type')
                        ->disabled()
                        ->columnSpan(1),
                        
                    Select::make('model_class')
                        ->label('Laravel Model')
                        ->options($modelOptions)
                        ->searchable()
                        ->placeholder('Select a model...')
                        ->columnSpan(1),
                        
                    Placeholder::make('stats')
                        ->label('Count')
                        ->content(fn($record) => $this->getPostTypeStats($record['post_type'], $mappingData))
                        ->columnSpan(1),
                ]),
                
                Placeholder::make('suggestions')
                    ->label('Suggested Matches')
                    ->content(fn($record) => $this->formatSuggestions($record['post_type'], $mappingData))
                    ->visible(fn($record) => $this->hasSuggestions($record['post_type'], $mappingData)),
            ])
            ->default($this->getDefaultMappingData($mappingData))
            ->addable(false)
            ->deletable(false)
            ->reorderable(false);
    }
    
    protected function buildUnmappedSection(array $mappingData): Repeater
    {
        return Repeater::make('unmapped_types')
            ->label('Post Types Needing Models')
            ->schema([
                Grid::make(3)->schema([
                    TextInput::make('post_type')
                        ->label('Post Type')
                        ->disabled(),
                        
                    TextInput::make('suggested_model')
                        ->label('Suggested Model Name')
                        ->disabled(),
                        
                    Placeholder::make('action')
                        ->label('Action Needed')
                        ->content('Model will be created in next step'),
                ]),
            ])
            ->default($this->getUnmappedData($mappingData))
            ->addable(false)
            ->deletable(false)
            ->reorderable(false);
    }
    
    protected function getDefaultMappingData(array $mappingData): array
    {
        $data = [];
        
        foreach ($mappingData['mappings'] as $postType => $mapping) {
            $data[] = [
                'post_type' => $postType,
                'model_class' => $mapping['model_class'],
            ];
        }
        
        return $data;
    }
    
    protected function getUnmappedData(array $mappingData): array
    {
        $data = [];
        
        foreach ($mappingData['unmapped_types'] as $postType) {
            $suggestion = $mappingData['suggestions'][$postType] ?? null;
            
            $data[] = [
                'post_type' => $postType,
                'suggested_model' => $suggestion ? $suggestion['model_name'] : Str::studly($postType),
            ];
        }
        
        return $data;
    }
    
    protected function getPostTypeStats(string $postType, array $mappingData): string
    {
        $stats = $mappingData['post_types'][$postType] ?? null;
        return $stats ? number_format($stats['count']) . ' posts' : 'Unknown';
    }
    
    protected function formatSuggestions(string $postType, array $mappingData): string
    {
        $matches = $mappingData['model_matches'][$postType] ?? [];
        
        if (empty($matches)) {
            return 'No model matches found';
        }
        
        $suggestions = [];
        foreach (array_slice($matches, 0, 3) as $match) {
            $model = $match['model'];
            $suggestions[] = sprintf(
                '%s (%s confidence)',
                $model['name'],
                $match['confidence']
            );
        }
        
        return implode(', ', $suggestions);
    }
    
    protected function hasSuggestions(string $postType, array $mappingData): bool
    {
        $matches = $mappingData['model_matches'][$postType] ?? [];
        return !empty($matches);
    }
    
    public function handleFilamentFormSave(array $data): void
    {
        $mappingData = $this->getStateData('model_mapping');
        
        if (isset($data['model_mappings'])) {
            // Update mappings with user selections
            $updatedMappings = [];
            foreach ($data['model_mappings'] as $mapping) {
                $updatedMappings[$mapping['post_type']] = [
                    'model_class' => $mapping['model_class'],
                    'auto_mapped' => false,
                    'user_selected' => true,
                ];
            }
            
            $mappingData['mappings'] = $updatedMappings;
            $this->setStateData('model_mapping', $mappingData);
        }
        
        // Determine next step
        $unmappedTypes = $this->getUnmappedPostTypes($mappingData['mappings']);
        
        if (!empty($unmappedTypes)) {
            $this->setStateData('unmapped_for_creation', $unmappedTypes);
        }
        
        // Use the state machine's preferred transition instead of hardcoding
        $this->transitionToNextState($this->getImport());
    }
    
    protected function getUnmappedPostTypes(array $mappings): array
    {
        $unmapped = [];
        
        foreach ($mappings as $postType => $mapping) {
            if (empty($mapping['model_class'])) {
                $unmapped[] = $postType;
            }
        }
        
        return $unmapped;
    }
}