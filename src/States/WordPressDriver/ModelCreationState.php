<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Services\ModelGenerator;
use Crumbls\Importer\Filament\Pages\GenericFormPage;
use Crumbls\Importer\States\WordPressDriver\ModelCustomizationState;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class ModelCreationState extends AbstractState
{
    protected ModelGenerator $generator;
    
    public function __construct()
    {
        $this->generator = new ModelGenerator();
    }
    
    public function label(): string
    {
        return 'Transform - Model Creation';
    }
    
    public function description(): string
    {
        return 'Create Laravel models for unmapped post types';
    }
    
    public function canEnter(): bool
    {
        return $this->getStateData('unmapped_for_creation') !== null;
    }
    
    public function onEnter(): void
    {
        $this->prepareModelCreationData();
        
        // Note: Auto-skip logic moved to form save handler to avoid duplicate transitions
        // The shouldAutoSkip() and handleAutoSkip() methods are still available for CLI usage
    }
    
    protected function shouldAutoSkip(): bool
    {
        $unmappedTypes = $this->getStateData('unmapped_for_creation');
        
        // Skip if no unmapped types need model creation
        return empty($unmappedTypes);
    }
    
    protected function handleAutoSkip(): void
    {
        // Log the auto-skip for transparency
        $this->setStateData('model_creation_auto_skipped', [
            'skipped' => true,
            'reason' => 'No models needed - all post types already have existing models',
            'timestamp' => now(),
        ]);
        
        // Continue to customization
        $this->transitionTo(ModelCustomizationState::class);
    }
    
    protected function prepareModelCreationData(): void
    {
        $unmappedTypes = $this->getStateData('unmapped_for_creation');
        $analysisData = $this->getStateData('analysis');
        
        if (!$unmappedTypes || !$analysisData) {
            $this->setStateData('creation_error', 'Missing required data');
            return;
        }
        
        $creationData = [];
        
        foreach ($unmappedTypes as $postType) {
            $postTypeData = $analysisData['post_types'][$postType] ?? [];
            $metaFields = $this->extractMetaFields($postType, $analysisData);
            
            $creationData[$postType] = [
                'post_type' => $postType,
                'model_name' => Str::studly(Str::singular($postType)),
                'table_name' => Str::snake(Str::plural($postType)),
                'namespace' => 'App\\Models',
                'fillable_fields' => $this->generateFillableFields($postTypeData, $metaFields),
                'relationships' => $this->detectRelationships($postType, $analysisData),
                'create_migration' => true,
                'create_factory' => false,
                'create_seeder' => false,
                'post_count' => $postTypeData['count'] ?? 0,
                'meta_fields' => $metaFields,
            ];
        }
        
        $this->setStateData('model_creation', $creationData);
    }
    
    protected function extractMetaFields(string $postType, array $analysisData): array
    {
        $metaFields = [];
        
        if (isset($analysisData['meta_fields'])) {
            foreach ($analysisData['meta_fields'] as $field) {
                // Filter meta fields that are commonly used with this post type
                // This would require more sophisticated analysis in a real implementation
                $metaFields[] = [
                    'key' => $field['field_name'],
                    'type' => $field['type'],
                    'confidence' => $field['confidence'],
                ];
            }
        }
        
        return $metaFields;
    }
    
    protected function generateFillableFields(array $postTypeData, array $metaFields): array
    {
        // Standard WordPress post fields
        $standardFields = [
            'title',
            'content',
            'excerpt',
            'status',
            'published_at',
            'slug',
        ];
        
        // Add commonly used meta fields as direct model fields
        $metaFieldNames = array_map(fn($meta) => Str::snake($meta['key']), $metaFields);
        
        return array_merge($standardFields, array_slice($metaFieldNames, 0, 10)); // Limit to first 10
    }
    
    protected function detectRelationships(string $postType, array $analysisData): array
    {
        $relationships = [];
        
        // Common WordPress relationships
        if (isset($analysisData['post_types']['attachment'])) {
            $relationships[] = [
                'type' => 'hasMany',
                'related' => 'App\\Models\\Attachment',
                'method' => 'attachments',
            ];
        }
        
        if (isset($analysisData['post_types']['user'])) {
            $relationships[] = [
                'type' => 'belongsTo',
                'related' => 'App\\Models\\User',
                'method' => 'author',
            ];
        }
        
        return $relationships;
    }
    
    public function getRecommendedPageClass(): string
    {
        return GenericFormPage::class;
    }
    
    public function form(Schema $schema): Schema
    {
        $creationData = $this->getStateData('model_creation');
        
        if (!$creationData) {
            return $schema->schema([
                Placeholder::make('error')
                    ->content('No model creation data available.')
            ]);
        }
        
        return $schema->schema([
            Section::make('Model Generation')
                ->description('Configure the models to be created for your WordPress post types.')
                ->schema([
                    Repeater::make('models')
                        ->label('Models to Create')
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('post_type')
                                    ->label('Post Type')
                                    ->disabled()
                                    ->columnSpan(1),
                                    
                                TextInput::make('model_name')
                                    ->label('Model Name')
                                    ->required()
                                    ->rule('regex:/^[A-Z][a-zA-Z0-9]*$/')
                                    ->helperText('PascalCase model name (e.g., BlogPost)')
                                    ->columnSpan(1),
                            ]),
                            
                            Grid::make(2)->schema([
                                TextInput::make('table_name')
                                    ->label('Table Name')
                                    ->required()
                                    ->rule('regex:/^[a-z][a-z0-9_]*$/')
                                    ->helperText('Snake_case table name (e.g., blog_posts)')
                                    ->columnSpan(1),
                                    
                                TextInput::make('namespace')
                                    ->label('Namespace')
                                    ->required()
                                    ->default('App\\Models')
                                    ->columnSpan(1),
                            ]),
                            
                            Textarea::make('fillable_fields')
                                ->label('Fillable Fields')
                                ->helperText('Comma-separated list of fillable fields')
                                ->formatStateUsing(fn($state) => is_array($state) ? implode(', ', $state) : $state),
                                
                            Grid::make(3)->schema([
                                Checkbox::make('create_migration')
                                    ->label('Create Migration')
                                    ->default(true),
                                    
                                Checkbox::make('create_factory')
                                    ->label('Create Factory')
                                    ->default(false),
                                    
                                Checkbox::make('create_seeder')
                                    ->label('Create Seeder')
                                    ->default(false),
                            ]),
                            
                            Placeholder::make('info')
                                ->content(fn($record) => $this->getModelInfo($record))
                        ])
                        ->default($this->getDefaultCreationData($creationData))
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->collapsible(),
                ])
        ]);
    }
    
    protected function getDefaultCreationData(array $creationData): array
    {
        return array_values($creationData);
    }
    
    protected function getModelInfo(array $record): string
    {
        $info = [];
        
        if (isset($record['post_count'])) {
            $info[] = "Posts to import: " . number_format($record['post_count']);
        }
        
        if (isset($record['meta_fields'])) {
            $info[] = "Meta fields: " . count($record['meta_fields']);
        }
        
        $filePath = app_path('Models/' . $record['model_name'] . '.php');
        if (File::exists($filePath)) {
            $info[] = "⚠️ Model file already exists";
        }
        
        return implode(' | ', $info);
    }
    
    public function handleFilamentFormSave(array $data): void
    {
        try {
            // Check if we should auto-skip this state
            if ($this->shouldAutoSkip()) {
                $this->handleAutoSkip();
                return;
            }
            
            $results = [];
            
            foreach ($data['models'] as $modelData) {
                $result = $this->generator->createModel([
                    'name' => $modelData['model_name'],
                    'table' => $modelData['table_name'],
                    'namespace' => $modelData['namespace'],
                    'fillable' => $this->parseFillableFields($modelData['fillable_fields']),
                    'create_migration' => $modelData['create_migration'] ?? false,
                    'create_factory' => $modelData['create_factory'] ?? false,
                    'create_seeder' => $modelData['create_seeder'] ?? false,
                ]);
                
                $results[$modelData['post_type']] = $result;
            }
            
            $this->setStateData('model_creation_results', $results);
            $this->updateModelMappings($data['models']);
            
            $this->transitionTo(ModelCustomizationState::class);
            
        } catch (\Exception $e) {
            $this->setStateData('creation_error', $e->getMessage());
            throw $e;
        }
    }
    
    protected function parseFillableFields(string $fillableFields): array
    {
        return array_map('trim', explode(',', $fillableFields));
    }
    
    protected function updateModelMappings(array $createdModels): void
    {
        $mappingData = $this->getStateData('model_mapping');
        
        foreach ($createdModels as $modelData) {
            $fullClass = $modelData['namespace'] . '\\' . $modelData['model_name'];
            
            $mappingData['mappings'][$modelData['post_type']] = [
                'model_class' => $fullClass,
                'auto_mapped' => false,
                'user_created' => true,
            ];
        }
        
        $this->setStateData('model_mapping', $mappingData);
    }
}