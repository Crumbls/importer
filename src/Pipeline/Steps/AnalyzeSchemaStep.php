<?php

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;

/**
 * Analyze data schema to determine Laravel artifacts needed
 * 
 * Now uses the Universal Schema Analyzer for consistency across all drivers
 */
class AnalyzeSchemaStep
{
    protected UniversalSchemaAnalyzer $analyzer;
    
    public function __construct()
    {
        $this->analyzer = new UniversalSchemaAnalyzer();
    }
    
    public function execute(PipelineContext $context): void
    {
        // Use the universal analyzer (works with any driver)
        $schema = $this->analyzer->analyze($context);
        
        // Add driver-specific checks
        $schema['requires_model'] = !$this->modelExists($schema['model_name']);
        $schema['requires_migration'] = !$this->tableExists($schema['table_name']);
        
        $context->set('schema_analysis', $schema);
        $context->set('requires_model', $schema['requires_model']);
        $context->set('requires_migration', $schema['requires_migration']);
    }
    
    protected function modelExists(string $modelName): bool
    {
        return class_exists("App\\Models\\{$modelName}");
    }
    
    protected function tableExists(string $tableName): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable($tableName);
        } catch (\Exception $e) {
            return false; // Assume table doesn't exist if we can't check
        }
    }
}