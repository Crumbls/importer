<?php

declare(strict_types=1);

namespace Crumbls\Importer\Pipeline\Steps;

use Crumbls\Importer\Pipeline\PipelineContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Execute the generated migration
 */
class RunMigrationStep
{
    public function execute(PipelineContext $context): void
    {
        $migrationResult = $context->get('migration_generation_result');
        if (!$migrationResult || !$migrationResult['created']) {
            throw new \RuntimeException('No migration was generated to run');
        }
        
        $tableName = $migrationResult['table_name'];
        $migrationPath = $migrationResult['migration_path'];
        
        // Check if table already exists
        if (Schema::hasTable($tableName)) {
            $context->set('migration_execution_result', [
                'executed' => false,
                'skipped' => true,
                'reason' => "Table '{$tableName}' already exists",
                'table_name' => $tableName
            ]);
            return;
        }
        
        try {
            // Run the migration
            $exitCode = Artisan::call('migrate', [
                '--path' => $this->getRelativeMigrationPath($migrationPath),
                '--force' => true // Skip confirmation in production
            ]);
            
            if ($exitCode === 0) {
                // Verify table was created
                if (Schema::hasTable($tableName)) {
                    $columnCount = count(Schema::getColumnListing($tableName));
                    
                    $context->set('migration_execution_result', [
                        'executed' => true,
                        'success' => true,
                        'table_name' => $tableName,
                        'migration_path' => $migrationPath,
                        'columns_created' => $columnCount,
                        'artisan_output' => Artisan::output()
                    ]);
                } else {
                    throw new \RuntimeException("Migration ran but table '{$tableName}' was not created");
                }
            } else {
                throw new \RuntimeException("Migration failed with exit code: {$exitCode}");
            }
            
        } catch (\Exception $e) {
            $context->set('migration_execution_result', [
                'executed' => true,
                'success' => false,
                'error' => $e->getMessage(),
                'table_name' => $tableName,
                'migration_path' => $migrationPath,
                'artisan_output' => Artisan::output()
            ]);
            
            // Don't throw - let the pipeline continue, but mark as failed
        }
    }
    
    protected function getRelativeMigrationPath(string $fullPath): string
    {
        // Convert full path to relative path for Artisan
        $basePath = database_path('migrations');
        
        if (str_starts_with($fullPath, $basePath)) {
            return 'database/migrations/' . basename($fullPath);
        }
        
        return basename($fullPath);
    }
}