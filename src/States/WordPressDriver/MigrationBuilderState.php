<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Services\MigrationBuilder;
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

class MigrationBuilderState extends AbstractState
{
    protected MigrationBuilder $builder;
    
    public function __construct()
    {
        $this->builder = new MigrationBuilder();
    }
    
    public function label(): string
    {
        return 'Transform - Migrations';
    }
    
    public function description(): string
    {
        return 'Generate database migrations for your models';
    }
    
    public function canEnter(): bool
    {
        return $this->getStateData('model_customization_final') !== null;
    }
    
    public function onEnter(): void
    {
        $this->generateMigrations();
    }
    
    protected function generateMigrations(): void
    {
        $customizationData = $this->getStateData('model_customization_final');
        
        if (!$customizationData) {
            return;
        }
        
        $migrations = [];
        
        foreach ($customizationData as $postType => $modelData) {
            $migrationCode = $this->builder->generateMigrationCode($modelData);
            
            $migrations[$postType] = [
                'post_type' => $postType,
                'table_name' => $modelData['table_name'],
                'migration_name' => 'create_' . $modelData['table_name'] . '_table',
                'migration_code' => $migrationCode,
                'file_path' => $this->generateMigrationFilePath($modelData['table_name']),
                'exists' => false,
                'can_run' => true,
            ];
        }
        
        // Check for existing migrations
        foreach ($migrations as &$migration) {
            $migration['exists'] = File::exists($migration['file_path']);
        }
        
        $this->setStateData('migrations', $migrations);
    }
    
    protected function generateMigrationFilePath(string $tableName): string
    {
        $timestamp = date('Y_m_d_His');
        $migrationName = "create_{$tableName}_table";
        return database_path("migrations/{$timestamp}_{$migrationName}.php");
    }
    
    public function getRecommendedPageClass(): string
    {
        return GenericFormPage::class;
    }
    
    public function form(Schema $schema): Schema
    {
        $migrations = $this->getStateData('migrations');
        
        if (!$migrations) {
            return $schema->schema([
                Placeholder::make('error')
                    ->content('No migration data available.')
            ]);
        }
        
        return $schema->schema([
            Section::make('Database Migrations')
                ->description('Review and customize the generated migrations before creating them.')
                ->schema([
                    Repeater::make('migrations')
                        ->label('Migrations to Create')
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('post_type')
                                    ->label('Post Type')
                                    ->disabled(),
                                    
                                TextInput::make('table_name')
                                    ->label('Table Name')
                                    ->disabled(),
                                    
                                TextInput::make('migration_name')
                                    ->label('Migration Name')
                                    ->required(),
                            ]),
                            
                            Textarea::make('migration_code')
                                ->label('Migration Code')
                                ->rows(20)
                                ->required()
                                ->helperText('You can edit this code before creating the migration'),
                                
                            Grid::make(2)->schema([
                                Checkbox::make('can_run')
                                    ->label('Create this migration')
                                    ->default(true),
                                    
                                Placeholder::make('status')
                                    ->label('Status')
                                    ->content(fn($record) => $this->getMigrationStatus($record)),
                            ]),
                        ])
                        ->default(array_values($migrations))
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn($state) => $state['migration_name'] ?? 'Unknown'),
                ])
        ]);
    }
    
    protected function getMigrationStatus(array $migration): string
    {
        if ($migration['exists'] ?? false) {
            return '⚠️ Migration file already exists';
        }
        
        if (!($migration['can_run'] ?? true)) {
            return '⏸️ Will be skipped';
        }
        
        return '✅ Ready to create';
    }
    
    public function handleFilamentFormSave(array $data): void
    {
        $results = [];
        
        foreach ($data['migrations'] as $migration) {
            if (!($migration['can_run'] ?? true)) {
                continue;
            }
            
            try {
                $result = $this->createMigrationFile($migration);
                $results[$migration['post_type']] = $result;
                
            } catch (\Exception $e) {
                $results[$migration['post_type']] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        $this->setStateData('migration_results', $results);
        $this->transitionTo(FactoryBuilderState::class);
    }
    
    protected function createMigrationFile(array $migration): array
    {
        $filePath = $this->generateMigrationFilePath($migration['table_name']);
        
        if (File::exists($filePath)) {
            throw new \Exception("Migration file already exists: {$filePath}");
        }
        
        $this->ensureDirectoryExists(dirname($filePath));
        File::put($filePath, $migration['migration_code']);
        
        return [
            'success' => true,
            'file_path' => $filePath,
            'migration_name' => $migration['migration_name'],
        ];
    }
    
    protected function ensureDirectoryExists(string $directory): void
    {
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }
}