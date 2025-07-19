<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Services\ModelScanner;
use Crumbls\Importer\Filament\Pages\GenericFormPage;
use Crumbls\Importer\Resolvers\ModelResolver;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\WordPressDriver\ModelCreationState;
use Crumbls\Importer\States\WordPressDriver\ModelCustomizationState;
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
        // Check if the import has required data for mapping
        $import = $this->getRecord();
        $metadata = $import->metadata ?? [];
        
        // Ensure we have analyzed data to work with
        return isset($metadata['data_map']) && !empty($metadata['data_map']);
    }
    
    public function onEnter(): void
    {
        $this->generateModelMappings();
        
        // Note: Auto-skip logic moved to CLI handling to avoid duplicate transitions
        // The shouldAutoSkip() and handleAutoSkip() methods are still available for CLI usage
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

		dd($unmappedTypes);
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
        
        $import = $this->getRecord();
        $postTypes = $analysisData['post_types'];
        $scanner = $this->getModelScanner();
        
        // Clear existing mappings for this import (force delete to avoid unique constraint issues)
        $import->modelMaps()->forceDelete();
        
        // Generate model matches and suggestions
        $modelMatches = $scanner->findModelMatches($postTypes);
        $suggestions = $scanner->suggestModelNames(array_keys($postTypes));
        
        // Create ImportModelMap records for each post type
        foreach ($postTypes as $postType => $typeData) {
            $this->createMappingForPostType($import, $postType, $typeData, $modelMatches, $suggestions);
        }
        
        // Also create mappings for meta fields if needed
        if (isset($analysisData['meta_fields'])) {
            $this->createMappingForMetaFields($import, $analysisData['meta_fields']);
        }
        
        // Generate default mappings based on model matches
        $mappings = $this->generateDefaultMappings($modelMatches);
        
        // Store analysis data for reference
        $this->setStateData('model_mapping', [
            'post_types' => $postTypes,
            'model_matches' => $modelMatches,
            'suggestions' => $suggestions,
            'mappings' => $mappings,
            'mappings_created' => true,
        ]);
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

	    $mappingData['mappings'] = isset($mappingData['mappings']) ? $mappingData['mappings'] : [];

        // Determine next step
        $unmappedTypes = $this->getUnmappedPostTypes($mappingData['mappings']);
        
        if (!empty($unmappedTypes)) {
            $this->setStateData('unmapped_for_creation', $unmappedTypes);
            $this->transitionTo(ModelCreationState::class);
        } else {
            $this->transitionTo(ModelCustomizationState::class);
        }
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
    
    /**
     * Create an ImportModelMap record for a specific post type
     */
    protected function createMappingForPostType(ImportContract $import, string $postType, array $typeData, array $modelMatches, array $suggestions): void
    {
        $matches = $modelMatches[$postType] ?? [];
        $bestMatch = !empty($matches) ? $matches[0] : null;
        
        // Determine target model
        $targetModel = null;
        $confidence = 'low';
        
        if ($bestMatch && $bestMatch['confidence'] === 'high') {
            $targetModel = $bestMatch['model']['class'];
            $confidence = 'high';
        } elseif (isset($suggestions[$postType])) {
            $targetModel = $suggestions[$postType];
            $confidence = 'medium';
        }
        
        // Create the mapping record
        $modelClass = ModelResolver::importModelMap();
        $modelClass::create([
            'import_id' => $import->id,
            'source_table' => 'posts',
            'source_type' => $postType,
            'target_model' => $targetModel,
            'target_table' => $targetModel ? $this->getTableFromModel($targetModel) : null,
            'field_mappings' => $this->generateDefaultFieldMappings($postType),
            'transformation_rules' => $this->generateDefaultTransformationRules($postType),
            'driver' => get_class($import->getDriver()),
            'is_active' => true,
            'priority' => $this->getPostTypePriority($postType),
            'metadata' => [
                'post_type_data' => $typeData,
                'confidence' => $confidence,
                'auto_mapped' => $confidence === 'high',
                'suggested_models' => $matches,
            ]
        ]);
    }
    
    /**
     * Create mappings for meta fields if needed
     */
    protected function createMappingForMetaFields(ImportContract $import, array $metaFields): void
    {
        // For now, create a general meta mapping
        // In the future, this could be more sophisticated
        $modelClass = ModelResolver::importModelMap();
        $modelClass::create([
            'import_id' => $import->id,
            'source_table' => 'postmeta',
            'source_type' => 'meta_field',
            'target_model' => null, // Will be determined later
            'target_table' => null,
            'field_mappings' => $this->generateMetaFieldMappings($metaFields),
            'transformation_rules' => $this->generateMetaTransformationRules($metaFields),
            'driver' => get_class($import->getDriver()),
            'is_active' => true,
            'priority' => 200, // Lower priority than post types
            'metadata' => [
                'meta_field_count' => count($metaFields),
                'is_meta_mapping' => true,
            ]
        ]);
    }
    
    /**
     * Get table name from model class
     */
    protected function getTableFromModel(string $modelClass): ?string
    {
        try {
            if (class_exists($modelClass)) {
                $instance = new $modelClass();
                if (method_exists($instance, 'getTable')) {
                    return $instance->getTable();
                }
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        
        return null;
    }
    
    /**
     * Generate default field mappings for a post type
     */
    protected function generateDefaultFieldMappings(string $postType): array
    {
        // Common WordPress to Laravel field mappings
        $baseMappings = [
            'ID' => 'id',
            'post_title' => 'title',
            'post_content' => 'content',
            'post_excerpt' => 'excerpt',
            'post_status' => 'status',
            'post_date' => 'created_at',
            'post_date_gmt' => 'created_at',
            'post_modified' => 'updated_at',
            'post_modified_gmt' => 'updated_at',
            'post_author' => 'user_id',
            'post_name' => 'slug',
            'post_parent' => 'parent_id',
            'menu_order' => 'sort_order',
            'guid' => 'guid',
        ];
        
        // Post type specific mappings
        $specificMappings = match($postType) {
            'page' => [
                'post_title' => 'title',
                'post_content' => 'content',
                'post_status' => 'status',
            ],
            'attachment' => [
                'post_title' => 'title',
                'post_content' => 'description',
                'post_excerpt' => 'caption',
                'guid' => 'url',
            ],
            default => []
        };
        
        return array_merge($baseMappings, $specificMappings);
    }
    
    /**
     * Generate default transformation rules
     */
    protected function generateDefaultTransformationRules(string $postType): array
    {
        return [
            'post_date' => ['type' => 'datetime', 'format' => 'Y-m-d H:i:s'],
            'post_date_gmt' => ['type' => 'datetime', 'format' => 'Y-m-d H:i:s'],
            'post_modified' => ['type' => 'datetime', 'format' => 'Y-m-d H:i:s'],
            'post_modified_gmt' => ['type' => 'datetime', 'format' => 'Y-m-d H:i:s'],
            'post_author' => ['type' => 'foreign_key', 'references' => 'users.id'],
            'post_parent' => ['type' => 'foreign_key', 'references' => 'posts.id'],
            'post_status' => ['type' => 'enum', 'allowed' => ['publish', 'draft', 'private', 'pending']],
        ];
    }
    
    /**
     * Generate meta field mappings
     */
    protected function generateMetaFieldMappings(array $metaFields): array
    {
        $mappings = [];
        
        foreach ($metaFields as $field) {
            $fieldName = $field['field_name'];
            $mappings[$fieldName] = $this->getMetaFieldMapping($fieldName);
        }
        
        return $mappings;
    }
    
    /**
     * Get mapping for a specific meta field
     */
    protected function getMetaFieldMapping(string $fieldName): string
    {
        // Common WordPress meta field mappings
        $commonMappings = [
            '_thumbnail_id' => 'featured_image_id',
            '_wp_page_template' => 'template',
            '_edit_last' => 'last_editor_id',
            '_edit_lock' => 'edit_lock',
            '_wp_attached_file' => 'file_path',
            '_wp_attachment_metadata' => 'attachment_metadata',
        ];
        
        return $commonMappings[$fieldName] ?? Str::snake(str_replace('_', '', $fieldName));
    }
    
    /**
     * Generate meta transformation rules
     */
    protected function generateMetaTransformationRules(array $metaFields): array
    {
        $rules = [];
        
        foreach ($metaFields as $field) {
            $fieldName = $field['field_name'];
            $fieldType = $field['type'] ?? 'string';
            
            $rules[$fieldName] = match($fieldType) {
                'integer' => ['type' => 'integer'],
                'datetime' => ['type' => 'datetime'],
                'boolean' => ['type' => 'boolean'],
                'json' => ['type' => 'json'],
                'url' => ['type' => 'url'],
                'email' => ['type' => 'email'],
                default => ['type' => 'string']
            };
        }
        
        return $rules;
    }
    
    /**
     * Get priority for post type (lower number = higher priority)
     */
    protected function getPostTypePriority(string $postType): int
    {
        return match($postType) {
            'post' => 10,
            'page' => 20,
            'attachment' => 30,
            'nav_menu_item' => 40,
            default => 100
        };
    }
}