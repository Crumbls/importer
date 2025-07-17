<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Filament\Pages\GenericFormPage;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Tabs;
use Illuminate\Support\Str;

class ModelCustomizationState extends AbstractState
{
    public function label(): string
    {
        return 'Transform - Customization';
    }
    
    public function description(): string
    {
        return 'Configure models, relationships, and database schema';
    }
    
    public function canEnter(): bool
    {
        return $this->getStateData('model_mapping') !== null;
    }
    
    public function onEnter(): void
    {
        $this->prepareCustomizationData();
    }
    
    protected function prepareCustomizationData(): void
    {
        $mappingData = $this->getStateData('model_mapping');
        $analysisData = $this->getStateData('analysis');
        
        if (!$mappingData || !$analysisData) {
            return;
        }
        
        $customizationData = [];
        
        foreach ($mappingData['mappings'] as $postType => $mapping) {
            $postTypeData = $analysisData['post_types'][$postType] ?? [];
            $metaFields = $this->getPostTypeMetaFields($postType, $analysisData);
            
            $customizationData[$postType] = [
                'post_type' => $postType,
                'model_class' => $mapping['model_class'],
                'table_name' => $this->getTableNameFromModel($mapping['model_class']),
                'columns' => $this->generateColumns($postTypeData, $metaFields),
                'relationships' => $this->detectRelationships($postType, $mappingData, $analysisData),
                'indexes' => $this->suggestIndexes($postTypeData, $metaFields),
                'casts' => $this->suggestCasts($metaFields),
                'fillable' => $this->generateFillable($metaFields),
                'model_exists' => class_exists($mapping['model_class'] ?? ''),
                'post_count' => $postTypeData['count'] ?? 0,
            ];
        }
        
        $this->setStateData('model_customization', $customizationData);
    }
    
    protected function getPostTypeMetaFields(string $postType, array $analysisData): array
    {
        // In a real implementation, you'd filter meta fields by post type
        return $analysisData['meta_fields'] ?? [];
    }
    
    protected function getTableNameFromModel(?string $modelClass): string
    {
        if (!$modelClass || !class_exists($modelClass)) {
            return '';
        }
        
        try {
            $instance = new $modelClass();
            return $instance->getTable();
        } catch (\Exception $e) {
            return Str::snake(Str::plural(class_basename($modelClass)));
        }
    }
    
    protected function generateColumns(array $postTypeData, array $metaFields): array
    {
        $columns = [
            [
                'name' => 'id',
                'type' => 'id',
                'nullable' => false,
                'default' => null,
                'primary' => true,
                'auto_increment' => true,
            ],
            [
                'name' => 'wordpress_id',
                'type' => 'unsignedBigInteger',
                'nullable' => false,
                'unique' => true,
                'default' => null,
                'comment' => 'Original WordPress post ID for syncing',
            ],
            [
                'name' => 'title',
                'type' => 'string',
                'length' => 255,
                'nullable' => false,
                'default' => null,
            ],
            [
                'name' => 'content',
                'type' => 'longText',
                'nullable' => true,
                'default' => null,
            ],
            [
                'name' => 'excerpt',
                'type' => 'text',
                'nullable' => true,
                'default' => null,
            ],
            [
                'name' => 'status',
                'type' => 'string',
                'length' => 20,
                'nullable' => false,
                'default' => 'draft',
            ],
            [
                'name' => 'slug',
                'type' => 'string',
                'length' => 255,
                'nullable' => false,
                'default' => null,
                'unique' => true,
            ],
            [
                'name' => 'published_at',
                'type' => 'datetime',
                'nullable' => true,
                'default' => null,
            ],
        ];
        
        // Add meta fields as columns
        foreach (array_slice($metaFields, 0, 10) as $meta) {
            $columns[] = [
                'name' => Str::snake($meta['field_name']),
                'type' => $this->mapMetaTypeToColumnType($meta['type']),
                'nullable' => true,
                'default' => null,
                'meta_field' => true,
                'original_meta_key' => $meta['field_name'],
            ];
        }
        
        return $columns;
    }
    
    protected function mapMetaTypeToColumnType(string $metaType): string
    {
        return match($metaType) {
            'integer' => 'integer',
            'float' => 'decimal',
            'boolean' => 'boolean',
            'datetime' => 'datetime',
            'json' => 'json',
            'url' => 'string',
            'email' => 'string',
            default => 'text',
        };
    }
    
    protected function detectRelationships(string $postType, array $mappingData, array $analysisData): array
    {
        $relationships = [];
        
        // User relationship (author)
        if (isset($mappingData['mappings']['user'])) {
            $relationships[] = [
                'name' => 'author',
                'type' => 'belongsTo',
                'related_model' => 'App\\Models\\User',
                'foreign_key' => 'user_id',
                'local_key' => 'id',
            ];
        }
        
        // Attachment relationship
        if (isset($mappingData['mappings']['attachment'])) {
            $relationships[] = [
                'name' => 'attachments',
                'type' => 'hasMany',
                'related_model' => $mappingData['mappings']['attachment']['model_class'],
                'foreign_key' => 'parent_id',
                'local_key' => 'id',
            ];
        }
        
        // Category/Term relationships
        foreach (['category', 'post_tag'] as $taxonomy) {
            if (isset($analysisData['taxonomies'][$taxonomy])) {
                $relationships[] = [
                    'name' => Str::plural($taxonomy),
                    'type' => 'belongsToMany',
                    'related_model' => 'App\\Models\\' . Str::studly($taxonomy),
                    'pivot_table' => $postType . '_' . Str::plural($taxonomy),
                    'foreign_pivot_key' => $postType . '_id',
                    'related_pivot_key' => $taxonomy . '_id',
                ];
            }
        }
        
        return $relationships;
    }
    
    protected function suggestIndexes(array $postTypeData, array $metaFields): array
    {
        return [
            ['columns' => ['slug'], 'unique' => true],
            ['columns' => ['status'], 'unique' => false],
            ['columns' => ['published_at'], 'unique' => false],
            ['columns' => ['user_id'], 'unique' => false],
        ];
    }
    
    protected function suggestCasts(array $metaFields): array
    {
        $casts = [
            'published_at' => 'datetime',
        ];
        
        foreach ($metaFields as $meta) {
            $columnName = Str::snake($meta['field_name']);
            $casts[$columnName] = match($meta['type']) {
                'integer' => 'integer',
                'boolean' => 'boolean',
                'datetime' => 'datetime',
                'json' => 'array',
                default => 'string',
            };
        }
        
        return $casts;
    }
    
    protected function generateFillable(array $metaFields): array
    {
        $fillable = ['wordpress_id', 'title', 'content', 'excerpt', 'status', 'slug', 'published_at'];
        
        foreach ($metaFields as $meta) {
            $fillable[] = Str::snake($meta['field_name']);
        }
        
        return $fillable;
    }
    
    public function getRecommendedPageClass(): string
    {
        return GenericFormPage::class;
    }
    
    public function form(Schema $schema): Schema
    {
        $customizationData = $this->getStateData('model_customization');
        
        if (!$customizationData) {
            return $schema->schema([
                Placeholder::make('error')
                    ->content('No customization data available.')
            ]);
        }
        
        return $schema->schema([
            Repeater::make('models')
                ->label('Model Configurations')
                ->schema([
                    Tabs::make('Configuration')
                        ->tabs([
                            $this->getBasicConfigTab(),
                            $this->getColumnsTab(),
                            $this->getRelationshipsTab(),
                            $this->getIndexesTab(),
                        ])
                ])
                ->default(array_values($customizationData))
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->collapsible()
                ->itemLabel(fn($state) => $state['post_type'] ?? 'Unknown'),
        ]);
    }
    
    protected function getBasicConfigTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Basic')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('post_type')
                        ->label('Post Type')
                        ->disabled(),
                        
                    TextInput::make('model_class')
                        ->label('Model Class')
                        ->disabled(),
                ]),
                
                Grid::make(2)->schema([
                    TextInput::make('table_name')
                        ->label('Table Name')
                        ->required(),
                        
                    Placeholder::make('post_count')
                        ->label('Posts to Import')
                        ->content(fn($record) => number_format($record['post_count'] ?? 0)),
                ]),
                
                Textarea::make('fillable')
                    ->label('Fillable Fields')
                    ->helperText('Comma-separated list')
                    ->formatStateUsing(fn($state) => is_array($state) ? implode(', ', $state) : $state),
                    
                Textarea::make('casts')
                    ->label('Attribute Casts')
                    ->helperText('JSON format: {"field": "type"}')
                    ->formatStateUsing(fn($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state),
            ]);
    }
    
    protected function getColumnsTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Columns')
            ->schema([
                Repeater::make('columns')
                    ->label('Database Columns')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('name')
                                ->label('Column Name')
                                ->required(),
                                
                            Select::make('type')
                                ->label('Column Type')
                                ->options([
                                    'id' => 'ID (Auto Increment)',
                                    'string' => 'String',
                                    'text' => 'Text',
                                    'longText' => 'Long Text',
                                    'integer' => 'Integer',
                                    'decimal' => 'Decimal',
                                    'boolean' => 'Boolean',
                                    'datetime' => 'DateTime',
                                    'timestamp' => 'Timestamp',
                                    'json' => 'JSON',
                                    'enum' => 'Enum',
                                ])
                                ->required(),
                                
                            TextInput::make('length')
                                ->label('Length')
                                ->numeric()
                                ->visible(fn($get) => in_array($get('type'), ['string', 'decimal'])),
                        ]),
                        
                        Grid::make(4)->schema([
                            Checkbox::make('nullable')
                                ->label('Nullable'),
                                
                            Checkbox::make('unique')
                                ->label('Unique'),
                                
                            Checkbox::make('primary')
                                ->label('Primary Key'),
                                
                            Checkbox::make('auto_increment')
                                ->label('Auto Increment')
                                ->visible(fn($get) => $get('type') === 'id'),
                        ]),
                        
                        TextInput::make('default')
                            ->label('Default Value')
                            ->helperText('Leave empty for NULL'),
                    ])
                    ->addable()
                    ->deletable()
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn($state) => $state['name'] ?? 'New Column'),
            ]);
    }
    
    protected function getRelationshipsTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Relationships')
            ->schema([
                Repeater::make('relationships')
                    ->label('Model Relationships')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Relationship Name')
                                ->required(),
                                
                            Select::make('type')
                                ->label('Relationship Type')
                                ->options([
                                    'hasOne' => 'Has One',
                                    'hasMany' => 'Has Many',
                                    'belongsTo' => 'Belongs To',
                                    'belongsToMany' => 'Belongs To Many',
                                    'morphTo' => 'Morph To',
                                    'morphOne' => 'Morph One',
                                    'morphMany' => 'Morph Many',
                                ])
                                ->required(),
                        ]),
                        
                        TextInput::make('related_model')
                            ->label('Related Model')
                            ->helperText('Full class name (e.g., App\\Models\\User)')
                            ->required(),
                            
                        Grid::make(2)->schema([
                            TextInput::make('foreign_key')
                                ->label('Foreign Key')
                                ->visible(fn($get) => in_array($get('type'), ['hasOne', 'hasMany', 'belongsTo'])),
                                
                            TextInput::make('local_key')
                                ->label('Local Key')
                                ->visible(fn($get) => in_array($get('type'), ['hasOne', 'hasMany', 'belongsTo'])),
                        ]),
                        
                        Grid::make(3)->schema([
                            TextInput::make('pivot_table')
                                ->label('Pivot Table')
                                ->visible(fn($get) => $get('type') === 'belongsToMany'),
                                
                            TextInput::make('foreign_pivot_key')
                                ->label('Foreign Pivot Key')
                                ->visible(fn($get) => $get('type') === 'belongsToMany'),
                                
                            TextInput::make('related_pivot_key')
                                ->label('Related Pivot Key')
                                ->visible(fn($get) => $get('type') === 'belongsToMany'),
                        ]),
                    ])
                    ->addable()
                    ->deletable()
                    ->collapsible()
                    ->itemLabel(fn($state) => $state['name'] ?? 'New Relationship'),
            ]);
    }
    
    protected function getIndexesTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Indexes')
            ->schema([
                Repeater::make('indexes')
                    ->label('Database Indexes')
                    ->schema([
                        Textarea::make('columns')
                            ->label('Columns')
                            ->helperText('Comma-separated list of column names')
                            ->formatStateUsing(fn($state) => is_array($state) ? implode(', ', $state) : $state)
                            ->required(),
                            
                        Grid::make(2)->schema([
                            Checkbox::make('unique')
                                ->label('Unique Index'),
                                
                            TextInput::make('name')
                                ->label('Index Name (optional)')
                                ->helperText('Auto-generated if empty'),
                        ]),
                    ])
                    ->addable()
                    ->deletable()
                    ->collapsible()
                    ->itemLabel(fn($state) => $state['columns'] ?? 'New Index'),
            ]);
    }
    
    public function handleFilamentFormSave(array $data): void
    {
        // Process and save the customization data
        $processedData = $this->processCustomizationData($data['models']);
        $this->setStateData('model_customization_final', $processedData);
        
        // Transition to migration builder
        $this->transitionTo(MigrationBuilderState::class);
    }
    
    protected function processCustomizationData(array $models): array
    {
        $processed = [];
        
        foreach ($models as $model) {
            $processed[$model['post_type']] = [
                'post_type' => $model['post_type'],
                'model_class' => $model['model_class'],
                'table_name' => $model['table_name'],
                'columns' => $model['columns'] ?? [],
                'relationships' => $model['relationships'] ?? [],
                'indexes' => $this->processIndexes($model['indexes'] ?? []),
                'fillable' => $this->processCommaSeparated($model['fillable'] ?? ''),
                'casts' => $this->processCasts($model['casts'] ?? ''),
            ];
        }
        
        return $processed;
    }
    
    protected function processIndexes(array $indexes): array
    {
        return array_map(function($index) {
            $index['columns'] = $this->processCommaSeparated($index['columns']);
            return $index;
        }, $indexes);
    }
    
    protected function processCommaSeparated(string $value): array
    {
        return array_map('trim', explode(',', $value));
    }
    
    protected function processCasts(string $casts): array
    {
        if (empty($casts)) {
            return [];
        }
        
        $decoded = json_decode($casts, true);
        return $decoded ?: [];
    }
}