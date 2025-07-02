<?php

namespace Crumbls\Importer\Pipeline;

/**
 * Configuration for Extended ETL Pipeline Steps
 */
class ExtendedPipelineConfiguration
{
    protected array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    protected function getDefaultConfig(): array
    {
        return [
            // Core ETL steps (always enabled)
            'core_steps' => [
                'validate' => true,
                'detect_delimiter' => true,
                'parse_headers' => true,
                'create_storage' => true,
                'process_rows' => true
            ],
            
            // Extended Laravel generation steps
            'laravel_steps' => [
                'analyze_schema' => false,        // Analyze data types and structure
                'generate_model' => false,        // Create Eloquent model
                'generate_migration' => false,    // Create database migration
                'generate_factory' => false,      // Create model factory
                'generate_seeder' => false,       // Create database seeder
                'generate_filament_resource' => false, // Create Filament admin resource
                'run_migration' => false,         // Execute migration automatically
                'seed_data' => false              // Run seeder automatically
            ],
            
            // Laravel generation options
            'laravel_options' => [
                'model_namespace' => 'App\\Models',
                'model_directory' => 'app/Models',
                'migration_directory' => 'database/migrations',
                'factory_directory' => 'database/factories',
                'seeder_directory' => 'database/seeders',
                'filament_directory' => 'app/Filament/Resources',
                
                // Model generation options
                'model_options' => [
                    'use_timestamps' => true,
                    'use_soft_deletes' => false,
                    'generate_relationships' => true,
                    'generate_scopes' => true,
                    'generate_accessors' => true,
                    'generate_validation_rules' => true
                ],
                
                // Migration options
                'migration_options' => [
                    'add_indexes' => true,
                    'add_foreign_keys' => false,  // Requires relationship detection
                    'add_comments' => true,
                    'optimize_for_search' => true
                ],
                
                // Factory options
                'factory_options' => [
                    'generate_realistic_data' => true,
                    'use_existing_data_patterns' => true,
                    'sample_size_for_patterns' => 100
                ],
                
                // Filament options
                'filament_options' => [
                    'generate_table' => true,
                    'generate_form' => true,
                    'generate_filters' => true,
                    'generate_actions' => true,
                    'add_bulk_actions' => true,
                    'add_export_action' => true
                ]
            ],
            
            // Data analysis options
            'analysis_options' => [
                'sample_size' => 1000,           // Records to sample for type detection
                'detect_relationships' => true,  // Look for foreign key patterns
                'suggest_indexes' => true,       // Recommend database indexes
                'detect_enums' => true,          // Find enum-like fields
                'analyze_patterns' => true       // Look for data patterns
            ],
            
            // Safety options
            'safety_options' => [
                'backup_existing_files' => true,  // Backup before overwriting
                'dry_run_mode' => false,          // Generate files to temp directory
                'confirm_before_overwrite' => true,
                'skip_if_exists' => false         // Skip generation if files exist
            ]
        ];
    }
    
    public function isStepEnabled(string $step): bool
    {
        // Check core steps
        if (isset($this->config['core_steps'][$step])) {
            return $this->config['core_steps'][$step];
        }
        
        // Check Laravel steps
        if (isset($this->config['laravel_steps'][$step])) {
            return $this->config['laravel_steps'][$step];
        }
        
        return false;
    }
    
    public function enableStep(string $step): self
    {
        if (isset($this->config['core_steps'][$step])) {
            $this->config['core_steps'][$step] = true;
        } elseif (isset($this->config['laravel_steps'][$step])) {
            $this->config['laravel_steps'][$step] = true;
        }
        
        return $this;
    }
    
    public function disableStep(string $step): self
    {
        if (isset($this->config['core_steps'][$step])) {
            $this->config['core_steps'][$step] = false;
        } elseif (isset($this->config['laravel_steps'][$step])) {
            $this->config['laravel_steps'][$step] = false;
        }
        
        return $this;
    }
    
    public function getStepConfig(string $step): array
    {
        return $this->config[$step] ?? [];
    }
    
    public function getLaravelOptions(): array
    {
        return $this->config['laravel_options'] ?? [];
    }
    
    public function getAnalysisOptions(): array
    {
        return $this->config['analysis_options'] ?? [];
    }
    
    public function getSafetyOptions(): array
    {
        return $this->config['safety_options'] ?? [];
    }
    
    // Fluent configuration methods
    public function withModelGeneration(array $options = []): self
    {
        $this->config['laravel_steps']['analyze_schema'] = true;
        $this->config['laravel_steps']['generate_model'] = true;
        
        if (!empty($options)) {
            $this->config['laravel_options']['model_options'] = array_merge(
                $this->config['laravel_options']['model_options'],
                $options
            );
        }
        
        return $this;
    }
    
    public function withMigrationGeneration(array $options = []): self
    {
        $this->config['laravel_steps']['analyze_schema'] = true;
        $this->config['laravel_steps']['generate_migration'] = true;
        
        if (!empty($options)) {
            $this->config['laravel_options']['migration_options'] = array_merge(
                $this->config['laravel_options']['migration_options'],
                $options
            );
        }
        
        return $this;
    }
    
    public function withFactoryGeneration(array $options = []): self
    {
        $this->config['laravel_steps']['analyze_schema'] = true;
        $this->config['laravel_steps']['generate_factory'] = true;
        
        if (!empty($options)) {
            $this->config['laravel_options']['factory_options'] = array_merge(
                $this->config['laravel_options']['factory_options'],
                $options
            );
        }
        
        return $this;
    }
    
    public function withFilamentGeneration(array $options = []): self
    {
        $this->config['laravel_steps']['analyze_schema'] = true;
        $this->config['laravel_steps']['generate_model'] = true;
        $this->config['laravel_steps']['generate_filament_resource'] = true;
        
        if (!empty($options)) {
            $this->config['laravel_options']['filament_options'] = array_merge(
                $this->config['laravel_options']['filament_options'],
                $options
            );
        }
        
        return $this;
    }
    
    public function withCompleteGeneration(): self
    {
        $this->config['laravel_steps']['analyze_schema'] = true;
        $this->config['laravel_steps']['generate_model'] = true;
        $this->config['laravel_steps']['generate_migration'] = true;
        $this->config['laravel_steps']['generate_factory'] = true;
        $this->config['laravel_steps']['generate_seeder'] = true;
        $this->config['laravel_steps']['generate_filament_resource'] = true;
        
        return $this;
    }
    
    public function withAutoExecution(): self
    {
        $this->config['laravel_steps']['run_migration'] = true;
        $this->config['laravel_steps']['seed_data'] = true;
        
        return $this;
    }
    
    public function dryRun(bool $dryRun = true): self
    {
        $this->config['safety_options']['dry_run_mode'] = $dryRun;
        
        return $this;
    }
    
    public function skipIfExists(bool $skip = true): self
    {
        $this->config['safety_options']['skip_if_exists'] = $skip;
        
        return $this;
    }
    
    public function withTableName(string $tableName): self
    {
        $this->config['table_name'] = $tableName;
        
        return $this;
    }
    
    public function withModelName(string $modelName): self
    {
        $this->config['model_name'] = $modelName;
        
        return $this;
    }
    
    public function toArray(): array
    {
        return $this->config;
    }
    
    public static function make(array $config = []): self
    {
        return new static($config);
    }
    
    // Preset configurations
    public static function quickModel(): self
    {
        return static::make()->withModelGeneration();
    }
    
    public static function quickMigration(): self
    {
        return static::make()->withMigrationGeneration();
    }
    
    public static function fullLaravel(): self
    {
        return static::make()->withCompleteGeneration();
    }
    
    public static function adminPanel(): self
    {
        return static::make()
            ->withModelGeneration()
            ->withMigrationGeneration()
            ->withFilamentGeneration()
            ->withAutoExecution();
    }
    
    // WordPress-specific preset configurations
    public static function completeApplication(): self
    {
        return static::make()->withCompleteGeneration()->withAutoExecution();
    }
    
    public static function contentManagement(): self
    {
        return static::make()
            ->withModelGeneration()
            ->withMigrationGeneration()
            ->withFilamentGeneration();
    }
    
    public static function userManagement(): self
    {
        return static::make()
            ->withModelGeneration()
            ->withMigrationGeneration()
            ->withFilamentGeneration();
    }
    
    public static function modelsOnly(): self
    {
        return static::make()
            ->withModelGeneration()
            ->withMigrationGeneration()
            ->withFactoryGeneration();
    }
    
    // Multi-model configuration methods
    public function withMultipleModels(array $models): self
    {
        $this->config['multiple_models'] = $models;
        return $this;
    }
    
    public function withRelationships(array $relationships): self
    {
        $this->config['relationships'] = $relationships;
        return $this;
    }
    
    public function withFilamentResources(array $resources): self
    {
        $this->config['filament_resources'] = $resources;
        $this->withFilamentGeneration();
        return $this;
    }
    
    public function withAdvancedFactories(array $factories): self
    {
        $this->config['advanced_factories'] = $factories;
        $this->withFactoryGeneration();
        return $this;
    }
    
    public function get(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }
}