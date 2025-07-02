<?php

namespace Crumbls\Importer\Adapters\Traits;

use Crumbls\Importer\Pipeline\ExtendedPipelineConfiguration;

/**
 * Trait for Laravel artifact generation capabilities
 * 
 * This trait can be used by ANY driver (CSV, XML, WPXML, etc.)
 * to add Laravel generation capabilities
 */
trait HasLaravelGeneration
{
    protected ?ExtendedPipelineConfiguration $extendedConfig = null;
    
    /**
     * Configure extended Laravel generation pipeline
     */
    public function withLaravelGeneration(ExtendedPipelineConfiguration $config = null): self
    {
        $this->extendedConfig = $config ?: ExtendedPipelineConfiguration::make();
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Quick setup for model generation
     */
    public function generateModel(array $options = []): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::quickModel();
        
        if (!empty($options)) {
            $this->extendedConfig->withModelGeneration($options);
        }
        
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Quick setup for migration generation
     */
    public function generateMigration(array $options = []): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::quickMigration();
        
        if (!empty($options)) {
            $this->extendedConfig->withMigrationGeneration($options);
        }
        
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Generate complete Laravel setup (Model + Migration + Factory + Filament)
     */
    public function generateLaravelStack(string $tableName = null, string $modelName = null): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::fullLaravel();
        
        if ($tableName) {
            $this->extendedConfig->withTableName($tableName);
        }
        
        if ($modelName) {
            $this->extendedConfig->withModelName($modelName);
        }
        
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Generate admin panel ready setup
     */
    public function generateAdminPanel(string $tableName = null): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::adminPanel();
        
        if ($tableName) {
            $this->extendedConfig->withTableName($tableName);
        }
        
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Generate WordPress-style multi-table setup
     */
    public function generateWordPressStack(array $tableConfig = []): self
    {
        $this->extendedConfig = ExtendedPipelineConfiguration::fullLaravel();
        
        // Configure for multiple WordPress tables
        foreach ($tableConfig as $table => $model) {
            $this->extendedConfig->withTableName($table);
            if ($model) {
                $this->extendedConfig->withModelName($model);
            }
        }
        
        $this->setupExtendedPipeline();
        return $this;
    }
    
    /**
     * Setup extended pipeline steps
     * 
     * This method is the same for ALL drivers
     */
    protected function setupExtendedPipeline(): void
    {
        if (!$this->extendedConfig) {
            return;
        }
        
        // Ensure we have access to the pipeline
        if (!isset($this->pipeline)) {
            throw new \RuntimeException('Pipeline not available. Ensure driver is properly initialized.');
        }
        
        // Add extended steps to pipeline if enabled
        if ($this->extendedConfig->isStepEnabled('analyze_schema')) {
            $this->pipeline->addStep('analyze_schema');
        }
        
        if ($this->extendedConfig->isStepEnabled('generate_model')) {
            $this->pipeline->addStep('generate_model');
        }
        
        if ($this->extendedConfig->isStepEnabled('generate_migration')) {
            $this->pipeline->addStep('generate_migration');
        }
        
        if ($this->extendedConfig->isStepEnabled('generate_factory')) {
            $this->pipeline->addStep('generate_factory');
        }
        
        if ($this->extendedConfig->isStepEnabled('generate_seeder')) {
            $this->pipeline->addStep('generate_seeder');
        }
        
        if ($this->extendedConfig->isStepEnabled('generate_filament_resource')) {
            $this->pipeline->addStep('generate_filament_resource');
        }
        
        if ($this->extendedConfig->isStepEnabled('run_migration')) {
            $this->pipeline->addStep('run_migration');
        }
        
        if ($this->extendedConfig->isStepEnabled('seed_data')) {
            $this->pipeline->addStep('seed_data');
        }
    }
    
    /**
     * Get the extended configuration
     */
    public function getExtendedConfig(): ?ExtendedPipelineConfiguration
    {
        return $this->extendedConfig;
    }
    
    /**
     * Check if Laravel generation is enabled
     */
    public function hasLaravelGeneration(): bool
    {
        return $this->extendedConfig !== null;
    }
    
    /**
     * Get Laravel generation results from pipeline context
     */
    public function getLaravelGenerationResults(): array
    {
        if (!isset($this->pipeline)) {
            return [];
        }
        
        $context = $this->pipeline->getContext();
        
        return [
            'schema_analysis' => $context->get('schema_analysis'),
            'model_generation' => $context->get('model_generation_result'),
            'migration_generation' => $context->get('migration_generation_result'),
            'factory_generation' => $context->get('factory_generation_result'),
            'filament_generation' => $context->get('filament_generation_result'),
        ];
    }
}