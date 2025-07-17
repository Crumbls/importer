<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Services\FactoryBuilder;
use Crumbls\Importer\Filament\Pages\GenericFormPage;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\File;

class FactoryBuilderState extends AbstractState
{
    protected FactoryBuilder $builder;
    
    public function __construct()
    {
        $this->builder = new FactoryBuilder();
    }
    
    public function label(): string
    {
        return 'Transform - Factories';
    }
    
    public function description(): string
    {
        return 'Generate model factories for testing and seeding';
    }
    
    public function canEnter(): bool
    {
        return $this->getStateData('model_customization_final') !== null;
    }
    
    public function onEnter(): void
    {
        $this->generateFactories();
    }
    
    protected function generateFactories(): void
    {
        $customizationData = $this->getStateData('model_customization_final');
        
        if (!$customizationData) {
            return;
        }
        
        $factories = [];
        
        foreach ($customizationData as $postType => $modelData) {
            $factoryCode = $this->builder->generateFactoryCode($modelData);
            $modelName = class_basename($modelData['model_class']);
            
            $factories[$postType] = [
                'post_type' => $postType,
                'model_class' => $modelData['model_class'],
                'factory_name' => $modelName . 'Factory',
                'factory_code' => $factoryCode,
                'file_path' => database_path("factories/{$modelName}Factory.php"),
                'exists' => false,
                'can_create' => true,
                'sample_count' => min(50, ($modelData['post_count'] ?? 0) ?: 10),
            ];
        }
        
        // Check for existing factories
        foreach ($factories as &$factory) {
            $factory['exists'] = File::exists($factory['file_path']);
        }
        
        $this->setStateData('factories', $factories);
    }
    
    public function getRecommendedPageClass(): string
    {
        return GenericFormPage::class;
    }
    
    public function form(Schema $schema): Schema
    {
        $factories = $this->getStateData('factories');
        
        if (!$factories) {
            return $schema->schema([
                Placeholder::make('error')
                    ->content('No factory data available.')
            ]);
        }
        
        return $schema->schema([
            Section::make('Model Factories')
                ->description('Generate factories for creating test data and seeding your database.')
                ->schema([
                    Repeater::make('factories')
                        ->label('Factories to Create')
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('post_type')
                                    ->label('Post Type')
                                    ->disabled(),
                                    
                                TextInput::make('factory_name')
                                    ->label('Factory Name')
                                    ->required(),
                                    
                                TextInput::make('sample_count')
                                    ->label('Sample Records')
                                    ->numeric()
                                    ->helperText('Number of records to generate for testing'),
                            ]),
                            
                            Textarea::make('factory_code')
                                ->label('Factory Code')
                                ->rows(15)
                                ->required()
                                ->helperText('You can customize the factory definition'),
                                
                            Grid::make(2)->schema([
                                Checkbox::make('can_create')
                                    ->label('Create this factory')
                                    ->default(true),
                                    
                                Placeholder::make('status')
                                    ->label('Status')
                                    ->content(fn($record) => $this->getFactoryStatus($record)),
                            ]),
                        ])
                        ->default(array_values($factories))
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn($state) => $state['factory_name'] ?? 'Unknown'),
                ])
        ]);
    }
    
    protected function getFactoryStatus(array $factory): string
    {
        if ($factory['exists'] ?? false) {
            return '⚠️ Factory file already exists';
        }
        
        if (!($factory['can_create'] ?? true)) {
            return '⏸️ Will be skipped';
        }
        
        return '✅ Ready to create';
    }
    
    public function handleFilamentFormSave(array $data): void
    {
        $results = [];
        
        foreach ($data['factories'] as $factory) {
            if (!($factory['can_create'] ?? true)) {
                continue;
            }
            
            try {
                $result = $this->createFactoryFile($factory);
                $results[$factory['post_type']] = $result;
                
            } catch (\Exception $e) {
                $results[$factory['post_type']] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        $this->setStateData('factory_results', $results);
        
        // Move to execution state - we're done with setup!
        $this->transitionTo(ExecuteState::class);
    }
    
    protected function createFactoryFile(array $factory): array
    {
        $filePath = $factory['file_path'];
        
        if (File::exists($filePath)) {
            throw new \Exception("Factory file already exists: {$filePath}");
        }
        
        $this->ensureDirectoryExists(dirname($filePath));
        File::put($filePath, $factory['factory_code']);
        
        return [
            'success' => true,
            'file_path' => $filePath,
            'factory_name' => $factory['factory_name'],
        ];
    }
    
    protected function ensureDirectoryExists(string $directory): void
    {
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }
}