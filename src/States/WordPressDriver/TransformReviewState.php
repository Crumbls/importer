<?php

namespace Crumbls\Importer\States\WordPressDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Filament\Pages\GenericInfolistPage;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Actions;
use Filament\Infolists\Components\Actions\Action;
use Illuminate\Support\Facades\File;

class TransformReviewState extends AbstractState
{
    public function label(): string
    {
        return 'Transform Review';
    }
    
    public function description(): string
    {
        return 'Review transformations and prepare for data loading';
    }
    
    public function canEnter(): bool
    {
        return $this->getStateData('factory_results') !== null;
    }
    
    public function onEnter(): void
    {
        $this->prepareReviewData();
    }
    
    protected function prepareReviewData(): void
    {
        $migrationResults = $this->getStateData('migration_results') ?? [];
        $factoryResults = $this->getStateData('factory_results') ?? [];
        $modelCustomization = $this->getStateData('model_customization_final') ?? [];
        
        $reviewData = [
            'migrations_created' => $this->getCreatedMigrations($migrationResults),
            'factories_created' => $this->getCreatedFactories($factoryResults),
            'models_configured' => count($modelCustomization),
            'ready_for_load' => $this->isReadyForLoad($migrationResults),
            'migration_commands' => $this->getMigrationCommands($migrationResults),
            'next_steps' => $this->getNextSteps($migrationResults),
            'warnings' => $this->getWarnings($migrationResults, $modelCustomization),
        ];
        
        $this->setStateData('transform_review', $reviewData);
    }
    
    protected function getCreatedMigrations(array $migrationResults): array
    {
        $created = [];
        
        foreach ($migrationResults as $postType => $result) {
            if ($result['success'] ?? false) {
                $created[] = [
                    'post_type' => $postType,
                    'file_path' => $result['file_path'],
                    'migration_name' => $result['migration_name'],
                    'exists' => File::exists($result['file_path']),
                ];
            }
        }
        
        return $created;
    }
    
    protected function getCreatedFactories(array $factoryResults): array
    {
        $created = [];
        
        foreach ($factoryResults as $postType => $result) {
            if ($result['success'] ?? false) {
                $created[] = [
                    'post_type' => $postType,
                    'file_path' => $result['file_path'],
                    'factory_name' => $result['factory_name'],
                    'exists' => File::exists($result['file_path']),
                ];
            }
        }
        
        return $created;
    }
    
    protected function isReadyForLoad(array $migrationResults): bool
    {
        // Check if migrations were created successfully
        foreach ($migrationResults as $result) {
            if (!($result['success'] ?? false)) {
                return false;
            }
        }
        
        return true;
    }
    
    protected function getMigrationCommands(array $migrationResults): array
    {
        $commands = [];
        
        if (!empty($migrationResults)) {
            $commands[] = [
                'command' => 'php artisan migrate',
                'description' => 'Run all pending migrations to create database tables',
                'required' => true,
            ];
            
            $commands[] = [
                'command' => 'php artisan migrate:status',
                'description' => 'Check migration status to verify tables were created',
                'required' => false,
            ];
        }
        
        return $commands;
    }
    
    protected function getNextSteps(array $migrationResults): array
    {
        $steps = [];
        
        if (!empty($migrationResults)) {
            $steps[] = [
                'step' => 'Run Migrations',
                'description' => 'Execute the generated migrations to create database tables',
                'action' => 'Run: php artisan migrate',
                'critical' => true,
            ];
        }
        
        $steps[] = [
            'step' => 'Begin Data Loading',
            'description' => 'Start importing WordPress data into your Laravel models',
            'action' => 'Continue to Load State',
            'critical' => false,
        ];
        
        $steps[] = [
            'step' => 'Verify Import',
            'description' => 'Check imported data and run any necessary post-import tasks',
            'action' => 'Review completion summary',
            'critical' => false,
        ];
        
        return $steps;
    }
    
    protected function getWarnings(array $migrationResults, array $modelCustomization): array
    {
        $warnings = [];
        
        // Check for potential data loss
        foreach ($modelCustomization as $postType => $config) {
            $tableName = $config['table_name'];
            
            // Check if table already exists
            if ($this->tableExists($tableName)) {
                $warnings[] = [
                    'type' => 'data_overwrite',
                    'message' => "Table '{$tableName}' already exists. Running migrations may affect existing data.",
                    'severity' => 'high',
                    'suggestion' => 'Consider backing up existing data before proceeding.',
                ];
            }
        }
        
        // Check for missing relationships
        foreach ($modelCustomization as $postType => $config) {
            $relationships = $config['relationships'] ?? [];
            foreach ($relationships as $relationship) {
                if ($relationship['type'] === 'belongsTo') {
                    $relatedModel = $relationship['related_model'];
                    if (!class_exists($relatedModel)) {
                        $warnings[] = [
                            'type' => 'missing_model',
                            'message' => "Related model '{$relatedModel}' does not exist.",
                            'severity' => 'medium',
                            'suggestion' => 'Ensure all related models are created before importing data.',
                        ];
                    }
                }
            }
        }
        
        return $warnings;
    }
    
    protected function tableExists(string $tableName): bool
    {
        try {
            return \Schema::hasTable($tableName);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getRecommendedPageClass(): string
    {
        return GenericInfolistPage::class;
    }
    
    public function infolist(Schema $schema): Schema
    {
        $reviewData = $this->getStateData('transform_review');
        
        if (!$reviewData) {
            return $schema->components([
                TextEntry::make('error')
                    ->label('Error')
                    ->default('No review data available.')
            ]);
        }
        
        return $schema->components([
            Section::make('Transformation Summary')
                ->schema([
                    TextEntry::make('models_configured')
                        ->label('Models Configured')
                        ->default($reviewData['models_configured'] ?? 0),
                        
                    TextEntry::make('migrations_count')
                        ->label('Migrations Created')
                        ->default(count($reviewData['migrations_created'] ?? [])),
                        
                    TextEntry::make('factories_count')
                        ->label('Factories Created')
                        ->default(count($reviewData['factories_created'] ?? [])),
                        
                    TextEntry::make('ready_status')
                        ->label('Ready for Load')
                        ->default($reviewData['ready_for_load'] ? '✅ Yes' : '❌ No')
                        ->color(fn() => $reviewData['ready_for_load'] ? 'success' : 'danger'),
                ]),
                
            Section::make('Required Actions')
                ->schema([
                    TextEntry::make('migration_commands')
                        ->label('Migration Commands')
                        ->default($this->formatCommands($reviewData['migration_commands'] ?? []))
                        ->html(),
                ])
                ->visible(fn() => !empty($reviewData['migration_commands'])),
                
            Section::make('Next Steps')
                ->schema([
                    KeyValueEntry::make('next_steps')
                        ->label('Action Plan')
                        ->keyLabel('Step')
                        ->valueLabel('Description')
                        ->default($this->formatNextSteps($reviewData['next_steps'] ?? [])),
                ]),
                
            Section::make('Warnings')
                ->schema([
                    TextEntry::make('warnings_list')
                        ->label('Important Notes')
                        ->default($this->formatWarnings($reviewData['warnings'] ?? []))
                        ->html(),
                ])
                ->visible(fn() => !empty($reviewData['warnings'])),
                
            Section::make('Created Files')
                ->schema([
                    KeyValueEntry::make('migrations_created')
                        ->label('Migration Files')
                        ->keyLabel('Post Type')
                        ->valueLabel('File Path')
                        ->default($this->formatCreatedFiles($reviewData['migrations_created'] ?? []))
                        ->visible(fn() => !empty($reviewData['migrations_created'])),
                        
                    KeyValueEntry::make('factories_created')
                        ->label('Factory Files')
                        ->keyLabel('Post Type')
                        ->valueLabel('File Path')
                        ->default($this->formatCreatedFiles($reviewData['factories_created'] ?? []))
                        ->visible(fn() => !empty($reviewData['factories_created'])),
                ]),
                
            Actions::make([
                Action::make('continue_to_load')
                    ->label('Continue to Data Loading')
                    ->color('primary')
                    ->action(function () {
                        $this->transitionTo(LoadState::class);
                    })
                    ->visible(fn() => $reviewData['ready_for_load'] ?? false),
                    
                Action::make('back_to_migrations')
                    ->label('Review Migrations')
                    ->color('gray')
                    ->action(function () {
                        $this->transitionTo(TransformMigrationsState::class);
                    }),
            ]),
        ]);
    }
    
    protected function formatCommands(array $commands): string
    {
        $formatted = [];
        
        foreach ($commands as $command) {
            $required = $command['required'] ? '<strong>[REQUIRED]</strong>' : '[OPTIONAL]';
            $formatted[] = "{$required} <code>{$command['command']}</code><br><small>{$command['description']}</small>";
        }
        
        return implode('<br><br>', $formatted);
    }
    
    protected function formatNextSteps(array $steps): array
    {
        $formatted = [];
        
        foreach ($steps as $step) {
            $critical = $step['critical'] ? ' (Critical)' : '';
            $formatted[$step['step'] . $critical] = $step['description'] . ' → ' . $step['action'];
        }
        
        return $formatted;
    }
    
    protected function formatWarnings(array $warnings): string
    {
        if (empty($warnings)) {
            return '<span style="color: green;">✅ No warnings detected</span>';
        }
        
        $formatted = [];
        
        foreach ($warnings as $warning) {
            $severity = match($warning['severity']) {
                'high' => '🔴',
                'medium' => '🟡',
                'low' => '🔵',
                default => '⚠️'
            };
            
            $formatted[] = "{$severity} <strong>{$warning['message']}</strong><br><small>💡 {$warning['suggestion']}</small>";
        }
        
        return implode('<br><br>', $formatted);
    }
    
    protected function formatCreatedFiles(array $files): array
    {
        $formatted = [];
        
        foreach ($files as $file) {
            $status = $file['exists'] ? '✅' : '❌';
            $formatted[$file['post_type']] = "{$status} {$file['file_path']}";
        }
        
        return $formatted;
    }
    
    public function handleFilamentFormSave(array $data): void
    {
        // This state is primarily informational, so we just transition to load
        $this->transitionTo(LoadState::class);
    }
}